<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\client\TencentCloud;
use app\lib\DnsHelper;

/**
 * 腾讯云 EdgeOne(CNAME 接入站点) 加速域名 → 目标 DNS 账户 CNAME 记录自动同步
 *
 * EO 侧只读：DescribeZones(zone-type=partial) + DescribeAccelerationDomains，
 * 不调用任何写接口；写入侧走 dnsmgr 现有的 DNS 驱动，只补缺失的 CNAME。
 */
class EoDnsSyncService
{
    const TEO_VERSION = '2022-09-01';
    const TEO_SERVICE = 'teo';
    const MAX_PAGES = 200;

    private $cfg;
    private $dryRun;
    private $uid;
    private $lockFile;
    private $lockHandle = null;

    public function __construct($dryRun = false, $uid = null)
    {
        $this->dryRun = (bool)$dryRun;
        $this->uid = $uid === null ? intval(Db::name('user')->where('level', 2)->order('id', 'asc')->value('id')) : intval($uid);
        $this->cfg = [
            'enable'    => (string)config_get('eo_sync_enable', '0'),
            'did'       => intval(config_get('eo_sync_did', 0)),
            'ttl'       => intval(config_get('eo_sync_ttl', 600)),
            'line'      => (string)config_get('eo_sync_line', '0'),
            'exclude'   => (string)config_get('eo_sync_exclude', ''),
            'overwrite' => (string)config_get('eo_sync_overwrite', '0'),
            'update'    => (string)config_get('eo_sync_update', '1'),
            'min'       => intval(config_get('eo_sync_min', 10)),
        ];
        if ($this->cfg['ttl'] < 1) $this->cfg['ttl'] = 600;
        if ($this->cfg['min'] < 1) $this->cfg['min'] = 10;
        $this->lockFile = runtime_path() . 'eosync.lock';
    }

    public function getConfig()
    {
        return $this->cfg;
    }

    /**
     * 定时入口（供 /cron 与 CLI 调用）
     * 加锁范围覆盖「间隔判断 + 计划 + 写入」，多入口并发时只会有一个真正执行
     */
    public function autoRun($force = false)
    {
        if ($this->cfg['enable'] !== '1' && !$force) return false;
        if (!$this->lock()) return false;
        try {
            $last = config_get('eo_sync_run_time', null, true);
            if (!$force && $last && strtotime($last) > time() - $this->cfg['min'] * 60) {
                return false;
            }
            // 先记「尝试时间」，避免执行失败时整分钟反复重试打爆 EO 接口
            config_set('eo_sync_attempt', date('Y-m-d H:i:s'));
            $result = $this->runLocked();
            // 成功时间在锁内写入，避免并发 cron 用旧节流时间再次进入
            config_set('eo_sync_run_time', date('Y-m-d H:i:s'));
            return $result;
        } finally {
            $this->unlock();
        }
    }

    /**
     * 执行一次同步
     */
    public function run()
    {
        if (!$this->lock()) {
            throw new Exception('同步任务正在运行中，请稍后再试');
        }
        try {
            return $this->runLocked();
        } finally {
            $this->unlock();
        }
    }

    private function runLocked()
    {
        $items = $this->buildPlan();
        $stat = ['total' => count($items), 'add' => 0, 'update' => 0, 'replace' => 0, 'exists' => 0, 'paused' => 0, 'conflict' => 0, 'skip' => 0, 'error' => 0, 'done' => 0];

        foreach ($items as $k => $item) {
            $action = $item['action'];
            if (!in_array($action, ['add', 'update', 'replace'], true)) {
                if (isset($stat[$action])) $stat[$action]++;
                continue;
            }
            if ($this->dryRun) continue;

            try {
                $dns = DnsHelper::getModel($item['aid'], $item['domain'], $item['thirdid']);
                if (!$dns) throw new Exception('DNS模块不存在');

                // 写前重查该主机记录，避免与后台手动操作或其他进程互相覆盖
                // 用完整区域快照过滤，兼容子域查询不精确/不分页的驱动
                $fresh = $this->loadFreshRecords($dns, $item['host']);
                $recheck = $this->decide($fresh, $item['cname']);
                if (!in_array($recheck['action'], ['add', 'update', 'replace'], true)) {
                    $stat[$recheck['action']] = isset($stat[$recheck['action']]) ? $stat[$recheck['action']] + 1 : 1;
                    $items[$k] = array_merge($item, $recheck);
                    continue;
                }

                $host = $item['host'];
                $value = $item['cname'];
                $line = $this->cfg['line'];
                $ttl = $this->cfg['ttl'];

                // 以二次确认的结果为准（计划与实际可能已不同）
                $exec = array_merge($item, $recheck);

                if ($recheck['action'] === 'replace') {
                    // 用二次确认读到的记录集，避免删掉已经变化的旧快照
                    $this->replaceRecords($dns, $exec, $recheck['records'], $value, $line, $ttl);
                    $stat['replace']++;
                } elseif ($recheck['action'] === 'update') {
                    $old = isset($recheck['record']) ? $recheck['record'] : null;
                    $args = [$recheck['record_id'], $host, 'CNAME', $value];
                    $args[] = $old ? $old['Line'] : $line;
                    $args[] = $old ? $old['TTL'] : $ttl;
                    $args[] = 1;
                    $args[] = null;
                    $args[] = $old ? $old['Remark'] : null;
                    if (!$dns->updateDomainRecord(...$args)) {
                        throw new Exception('修改失败：' . $dns->getError());
                    }
                    $this->addLog($item['domain'], 'EO同步',
                        $host . ' [CNAME] ' . (isset($exec['old_value']) ? $exec['old_value'] : '') . ' → ' . $value);
                    $stat['update']++;
                } else {
                    if (!$dns->addDomainRecord($host, 'CNAME', $value, $line, $ttl)) {
                        throw new Exception('添加失败：' . $dns->getError());
                    }
                    $this->addLog($item['domain'], 'EO同步', $host . ' [CNAME] ' . $value . ' (TTL:' . $ttl . ')');
                    $stat['add']++;
                }
                $stat['done']++;
                $items[$k]['result'] = 'success';
            } catch (Exception $e) {
                $stat['error']++;
                $items[$k]['result'] = 'fail';
                $items[$k]['message'] = $e->getMessage();
                $this->addLog(isset($item['domain']) ? $item['domain'] : '', 'EO同步失败',
                    (isset($item['fqdn']) ? $item['fqdn'] : '') . ' ' . $action . ' 失败：' . $e->getMessage());
            }
        }

        config_set('eo_sync_last_time', date('Y-m-d H:i:s'));
        config_set('eo_sync_last_result', json_encode($stat, JSON_UNESCAPED_UNICODE));
        config_set('eo_sync_last_add', json_encode(array_slice(array_values(array_filter($items, function ($v) {
            return isset($v['result']) && $v['result'] === 'success';
        })), 0, 50), JSON_UNESCAPED_UNICODE));

        return ['stat' => $stat, 'items' => array_slice($items, 0, 500), 'dry_run' => $this->dryRun];
    }

    /**
     * 替换 A/AAAA：删除前留快照，新记录写入失败时按快照回滚
     */
    private function replaceRecords($dns, $item, $records, $value, $line, $ttl)
    {
        $snapshot = [];
        foreach ($records as $old) {
            $snapshot[] = $old;
        }
        $deleted = [];
        try {
            foreach ($snapshot as $old) {
                if (!$dns->deleteDomainRecord($old['RecordId'])) {
                    throw new Exception('删除原 ' . $old['Type'] . ' 记录失败：' . $dns->getError());
                }
                $deleted[] = $old;
            }
            if (!$dns->addDomainRecord($item['host'], 'CNAME', $value, $line, $ttl)) {
                throw new Exception('添加 CNAME 失败：' . $dns->getError());
            }
        } catch (Throwable $e) {
            $failed = [];
            foreach ($deleted as $old) {
                try {
                    // 华为云等驱动的 Value 是数组，恢复时要拼成逗号串再交给驱动
                    $oldValue = is_array($old['Value']) ? implode(',', $old['Value']) : (string)$old['Value'];
                    $ok = $dns->addDomainRecord(
                        $old['Name'], $old['Type'], $oldValue,
                        isset($old['Line']) && $old['Line'] !== null ? $old['Line'] : '0',
                        isset($old['TTL']) && $old['TTL'] ? $old['TTL'] : 600,
                        isset($old['MX']) && $old['MX'] ? $old['MX'] : 1,
                        isset($old['Weight']) ? $old['Weight'] : null,
                        isset($old['Remark']) ? $old['Remark'] : null
                    );
                    if (!$ok) $failed[] = $old['Type'] . ' ' . $oldValue;
                } catch (Throwable $ignore) {
                    $failed[] = $old['Type'] . ' ' . $oldValue;
                }
            }
            if ($failed) {
                $this->addLog($item['domain'], 'EO同步回滚失败',
                    $item['host'] . ' 需人工恢复：' . implode('；', $failed));
            }
            throw $e;
        }
        $this->addLog($item['domain'], 'EO同步', $item['host'] . ' [CNAME] ' . $value
            . '（替换原有 ' . implode('/', array_values(array_unique(array_column($snapshot, 'Type')))) . ' 记录）');
    }

    /**
     * 只读生成同步计划
     */
    public function buildPlan()
    {
        $items = [];
        $exclude = $this->excludeList();
        foreach ($this->fetchEoSites() as $site) {
            if (!empty($site['error'])) {
                $items[] = [
                    'zone' => $site['zone'], 'account' => $site['account_name'], 'host' => '', 'fqdn' => '',
                    'domain' => '', 'type' => '', 'cname' => '', 'action' => 'error',
                    'message' => '获取EO站点失败：' . $site['error'], 'aid' => 0, 'thirdid' => '',
                ];
                continue;
            }
            try {
                $target = $this->resolveTargetDomain($site['zone']);
            } catch (Exception $e) {
                $items[] = [
                    'zone' => $site['zone'], 'account' => $site['account_name'], 'host' => '', 'fqdn' => '',
                    'domain' => '', 'type' => '', 'cname' => '', 'action' => 'error',
                    'message' => $e->getMessage(), 'aid' => 0, 'thirdid' => '',
                ];
                continue;
            }
            if (!$target) {
                $items[] = [
                    'zone' => $site['zone'], 'account' => $site['account_name'], 'host' => '', 'fqdn' => '',
                    'domain' => $site['zone'], 'type' => '', 'cname' => '', 'action' => 'skip',
                    'message' => 'DNS管理中未找到可写域名 ' . $site['zone'] . '，已跳过', 'aid' => 0, 'thirdid' => '',
                ];
                continue;
            }
            try {
                $records = $this->loadRecords($target);
            } catch (Exception $e) {
                $items[] = [
                    'zone' => $site['zone'], 'account' => $site['account_name'], 'host' => '', 'fqdn' => '',
                    'domain' => $target['name'], 'type' => '', 'cname' => '', 'action' => 'error',
                    'message' => '读取解析记录失败：' . $e->getMessage(), 'aid' => $target['aid'], 'thirdid' => $target['thirdid'],
                ];
                continue;
            }
            foreach ($site['domains'] as $row) {
                $host = $this->relativeName($row['name'], $target['name']);
                $item = [
                    'zone' => $site['zone'],
                    'account' => $site['account_name'],
                    'host' => (string)$host,
                    'fqdn' => $row['name'],
                    'domain' => $target['name'],
                    'aid' => $target['aid'],
                    'thirdid' => $target['thirdid'],
                    'type' => 'CNAME',
                    'cname' => $row['cname'],
                    'status' => $row['status'],
                ];
                if ($host === null) {
                    $item['action'] = 'skip';
                    $item['message'] = '加速域名不在 ' . $target['name'] . ' 下，未处理';
                    $items[] = $item;
                    continue;
                }
                if (in_array($host, $exclude, true) || in_array($row['name'], $exclude, true)) {
                    $item['action'] = 'skip';
                    $item['message'] = '命中排除名单';
                    $items[] = $item;
                    continue;
                }
                $key = strtolower($host);
                $items[] = array_merge($item, $this->decide(isset($records[$key]) ? $records[$key] : [], $row['cname']));
            }
        }
        return $this->dedupe($items);
    }

    /**
     * 同一目标区域 + 同一主机记录只能有一个来源
     * 多个 EO 账户/站点给出不同 CNAME 时无法自动判定，全部标冲突交给人
     */
    private function dedupe($items)
    {
        $groups = [];
        foreach ($items as $i => $item) {
            if (empty($item['aid']) || $item['host'] === '') continue;
            $groups[$item['aid'] . '|' . $item['domain'] . '|' . strtolower($item['host'])][] = $i;
        }
        foreach ($groups as $indexes) {
            if (count($indexes) < 2) continue;
            $values = [];
            foreach ($indexes as $i) {
                $values[] = strtolower($items[$i]['cname']);
            }
            if (count(array_unique($values)) === 1) {
                // 同一目标，保留第一条可执行项，其余标记为重复
                $kept = false;
                foreach ($indexes as $i) {
                    if (!$kept && in_array($items[$i]['action'], ['add', 'update', 'replace'], true)) {
                        $kept = true;
                        continue;
                    }
                    $items[$i]['action'] = 'skip';
                    $items[$i]['message'] = '与其他EO站点重复，已合并处理';
                }
                continue;
            }
            foreach ($indexes as $i) {
                $items[$i]['action'] = 'conflict';
                $items[$i]['message'] = '多个EO站点对该主机给出了不同 CNAME，需人工确认';
            }
        }
        return $items;
    }

    /**
     * 只读拉取 EO 站点与加速域名
     */
    public function fetchEoSites()
    {
        $sites = [];
        $accounts = Db::name('account')->where('type', 'tencenteo')->select();
        foreach ($accounts as $account) {
            $config = json_decode(isset($account['config']) ? $account['config'] : '', true);
            if (!is_array($config) || empty($config['SecretId']) || empty($config['SecretKey'])) {
                $sites[] = ['account_name' => $account['name'], 'zone' => '', 'zone_id' => '', 'domains' => [], 'error' => '账户缺少 SecretId/SecretKey'];
                continue;
            }
            try {
                $client = $this->teoClient($config);
                $zones = $this->fetchZones($client);
            } catch (Exception $e) {
                $sites[] = ['account_name' => $account['name'], 'zone' => '', 'zone_id' => '', 'domains' => [], 'error' => $e->getMessage()];
                continue;
            }
            foreach ($zones as $zone) {
                $entry = [
                    'account_id' => $account['id'],
                    'account_name' => $account['name'],
                    'zone' => $zone['ZoneName'],
                    'zone_id' => $zone['ZoneId'],
                    'domains' => [],
                ];
                try {
                    $entry['domains'] = $this->fetchAccelerationDomains($client, $zone['ZoneId']);
                } catch (Exception $e) {
                    $entry['error'] = '站点 ' . $zone['ZoneName'] . ' 读取加速域名失败：' . $e->getMessage();
                }
                $sites[] = $entry;
            }
        }
        return $sites;
    }

    private function teoClient($config)
    {
        $endpoint = (isset($config['site_type']) && $config['site_type'] === 'intl') ? 'teo.intl.tencentcloudapi.com' : 'teo.tencentcloudapi.com';
        $proxy = !empty($config['proxy']) && $config['proxy'] == 1;
        return new TencentCloud($config['SecretId'], $config['SecretKey'], $endpoint, self::TEO_SERVICE, self::TEO_VERSION, null, $proxy);
    }

    /** 只取 CNAME 接入（partial）站点，full 站点由 tencenteo 驱动自己管理 */
    private function fetchZones(TencentCloud $client)
    {
        $zones = [];
        $offset = 0;
        $limit = 100;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $res = $client->request('DescribeZones', [
                'Offset' => $offset,
                'Limit' => $limit,
                'Filters' => [['Name' => 'zone-type', 'Values' => ['partial']]],
            ]);
            if (!isset($res['Zones']) || !is_array($res['Zones'])) break;
            $list = $res['Zones'];
            foreach ($list as $row) {
                if (empty($row['ZoneId']) || empty($row['ZoneName'])) continue;
                $zones[] = $row;
            }
            $total = isset($res['TotalCount']) ? intval($res['TotalCount']) : 0;
            $offset += $limit;
            if (count($list) < $limit || ($total > 0 && $offset >= $total)) break;
        }
        return $zones;
    }

    private function fetchAccelerationDomains(TencentCloud $client, $zoneId)
    {
        $domains = [];
        $offset = 0;
        $limit = 100;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $res = $client->request('DescribeAccelerationDomains', [
                'ZoneId' => $zoneId,
                'Offset' => $offset,
                'Limit' => $limit,
            ]);
            if (!isset($res['AccelerationDomains']) || !is_array($res['AccelerationDomains'])) break;
            $list = $res['AccelerationDomains'];
            foreach ($list as $row) {
                $cname = isset($row['Cname']) ? trim($row['Cname']) : '';
                if ($cname === '' || empty($row['DomainName'])) continue;
                $domains[] = [
                    'name' => strtolower(trim($row['DomainName'], '.')),
                    'cname' => strtolower(trim($cname, '.')),
                    'status' => isset($row['DomainStatus']) ? $row['DomainStatus'] : '',
                ];
            }
            $total = isset($res['TotalCount']) ? intval($res['TotalCount']) : 0;
            $offset += $limit;
            if (count($list) < $limit || ($total > 0 && $offset >= $total)) break;
        }
        usort($domains, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return $domains;
    }

    /**
     * 目标域名：显式配置优先且必须存在；未配置时按站点名唯一匹配
     * 只接受非 tencenteo 类型的账户，避免再写回 EO
     */
    private function resolveTargetDomain($zoneName)
    {
        if ($this->cfg['did'] > 0) {
            $row = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')
                ->where('A.id', $this->cfg['did'])->field('A.*,B.type as account_type')->find();
            if (!$row) {
                throw new Exception('设置中指定的目标域名(ID=' . $this->cfg['did'] . ')不存在，请重新选择');
            }
            if ($row['account_type'] === 'tencenteo') {
                throw new Exception('目标域名 ' . $row['name'] . ' 属于腾讯云EO账户，不能作为写入目标');
            }
            return $row;
        }
        $rows = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')
            ->where('A.name', $zoneName)->where('B.type', '<>', 'tencenteo')
            ->field('A.*,B.type as account_type')->select();
        if (count($rows) > 1) {
            throw new Exception('存在多个同名域名 ' . $zoneName . '，请在设置中显式指定目标域名');
        }
        return $rows ? $rows[0] : null;
    }

    private function loadRecords($domain, $dns = null)
    {
        if ($dns === null) {
            $dns = DnsHelper::getModel($domain['aid'], $domain['name'], $domain['thirdid']);
        }
        if (!$dns) throw new Exception('DNS模块不存在');
        $map = [];
        $seen = [];
        $page = 1;
        while (true) {
            $res = $dns->getDomainRecords($page, 100);
            if ($res === false) throw new Exception($dns->getError());
            $list = isset($res['list']) ? $res['list'] : [];
            $added = 0;
            foreach ($list as $row) {
                $id = isset($row['RecordId']) ? (string)$row['RecordId'] : '';
                // 部分驱动（aws/powerdns/dynv6 等）忽略分页参数返回全量，按 RecordId 去重
                if ($id !== '') {
                    if (isset($seen[$id])) continue;
                    $seen[$id] = true;
                }
                // 部分驱动（青云等）列表接口只返回主机摘要，Type/Value 为空，需要再展开成真实记录
                if (empty($row['Type'])) {
                    foreach ($this->expandSummary($dns, $row) as $real) {
                        $rid = isset($real['RecordId']) ? (string)$real['RecordId'] : '';
                        if ($rid !== '') {
                            if (isset($seen[$rid])) continue;
                            $seen[$rid] = true;
                        }
                        $map[$this->normalizeName($real['Name'])][] = $real;
                        $added++;
                    }
                    continue;
                }
                $map[$this->normalizeName($row['Name'])][] = $row;
                $added++;
            }
            $total = intval(isset($res['total']) ? $res['total'] : 0);
            // 整页都是重复数据，说明该驱动不支持分页，已经拿全了
            if (count($list) < 100 || $page * 100 >= $total || ($added === 0 && $page > 1)) break;
            if ($page >= self::MAX_PAGES) {
                throw new Exception('解析记录超过 ' . (self::MAX_PAGES * 100) . ' 条，已中止以避免漏读记录');
            }
            $page++;
        }
        return $map;
    }

    /**
     * 主机摘要展开：列表接口只给了主机名和数量，记录明细要按主机再查一次
     */
    private function expandSummary($dns, $row)
    {
        $count = isset($row['Count']) ? intval($row['Count']) : 0;
        if ($count <= 0) return [];
        try {
            $res = $dns->getSubDomainRecords($row['Name'], 1, max(100, $count));
        } catch (Throwable $e) {
            return [];
        }
        if ($res === false || empty($res['list'])) return [];
        $out = [];
        foreach ($res['list'] as $item) {
            if (empty($item['Type'])) continue;
            $out[] = $item;
        }
        return $out;
    }

    /**
     * 写前重查：直接取一次完整区域快照再按主机过滤
     *
     * 各驱动的子域查询精度不一（有的按 FQDN 精确匹配、有的只读全区前 100 条、
     * 有的忽略分页返回全量），暂停记录还以 _pause 后缀存放，逐个主机名去查容易漏。
     * 这里统一按区域快照过滤，结果和 buildPlan 完全同源。
     */
    private function loadFreshRecords($dns, $host)
    {
        $map = $this->loadRecords(['aid' => 0, 'name' => '', 'thirdid' => ''], $dns);
        $key = $this->normalizeName($host);
        return isset($map[$key]) ? $map[$key] : [];
    }

    private function loadSubRecords($dns, $host)
    {
        $names = [$host, $host . '_pause'];
        if ($host === '@') $names[] = '__root___pause';
        $out = [];
        $seen = [];
        foreach (array_unique($names) as $name) {
            $page = 1;
            while (true) {
                $res = $dns->getSubDomainRecords($name, $page, 100);
                if ($res === false) break;
                $list = isset($res['list']) ? $res['list'] : [];
                foreach ($list as $row) {
                    $normalized = $this->normalizeName($row['Name']);
                    if ($normalized !== strtolower($host)) continue;
                    if (isset($seen[$row['RecordId']])) continue;
                    $seen[$row['RecordId']] = true;
                    $out[] = $row;
                }
                $total = intval(isset($res['total']) ? $res['total'] : 0);
                if (count($list) < 100 || $page * 100 >= $total || $page >= self::MAX_PAGES) break;
                $page++;
            }
        }
        return $out;
    }

    /**
     * 记录名归一：Cloudflare 根记录暂停时驱动返回 __root__（先判 __root__ 再剥 _pause 的顺序问题）
     */
    private function normalizeName($name)
    {
        $name = strtolower((string)$name);
        if (str_ends_with($name, '_pause')) $name = substr($name, 0, -6);
        if ($name === '__root__' || $name === '') return '@';
        return $name;
    }

    private function relativeName($fullName, $baseName)
    {
        $full = strtolower(rtrim($fullName, '.'));
        $base = strtolower(rtrim($baseName, '.'));
        if ($full === $base) return '@';
        $suffix = '.' . $base;
        if (substr($full, -strlen($suffix)) === $suffix) {
            $host = substr($full, 0, -strlen($suffix));
            return $host === '' ? null : $host;
        }
        return null;
    }

    /**
     * 记录值统一成字符串：华为云等驱动的 Value 是数组，直接 rtrim 会 TypeError
     */
    private function strValue($value)
    {
        if (is_array($value)) {
            $value = isset($value[0]) ? $value[0] : '';
        }
        return strtolower(rtrim(trim((string)$value), '.'));
    }

    private function isEoCname($value)
    {
        $value = $this->strValue($value);
        $suffix = '.eo.dnse5.com';
        return strlen($value) > strlen($suffix) && substr($value, -strlen($suffix)) === $suffix;
    }

    private function excludeList()
    {
        $list = [];
        foreach (explode(',', $this->cfg['exclude']) as $v) {
            $v = strtolower(rtrim(trim($v), '.'));
            if ($v !== '') $list[] = $v;
        }
        return $list;
    }

    /**
     * 比对单个主机记录下的现有解析记录
     *
     * Status=0 表示记录被暂停（Cloudflare 驱动会把 xxx_pause 映射回原名），
     * 暂停记录一律不自动改写或删除，交给人处理，避免把用户手动停用的记录重新激活。
     */
    private function decide($rows, $expected)
    {
        $expected = $this->strValue($expected);
        $cnames = [];
        $others = [];
        foreach ($rows as $row) {
            if (strtoupper($row['Type']) === 'CNAME') {
                $cnames[] = $row;
            } else {
                $others[] = $row;
            }
        }

        if (count($cnames) > 1) {
            return ['action' => 'conflict', 'record_id' => null, 'message' => '存在多条 CNAME 记录（可能为多线路解析），需人工确认'];
        }

        if ($cnames) {
            $cname = $cnames[0];
            $val = $this->strValue($cname['Value']);
            $paused = isset($cname['Status']) && (string)$cname['Status'] === '0';
            if ($val === $expected) {
                return [
                    'action' => $paused ? 'paused' : 'exists',
                    'record_id' => $cname['RecordId'],
                    'old_value' => $this->strValue($cname['Value']),
                    'message' => $paused ? 'CNAME 已存在但处于暂停状态，未改动' : '记录已存在',
                ];
            }
            if ($this->cfg['update'] === '1' && $this->isEoCname($val) && !$paused) {
                return [
                    'action' => 'update',
                    'record_id' => $cname['RecordId'],
                    'old_value' => $val,
                    'record' => $cname,
                    'message' => '旧EO CNAME 将被更新',
                ];
            }
            return [
                'action' => 'conflict',
                'record_id' => $cname['RecordId'],
                'old_value' => $val,
                'message' => ($paused ? '已存在暂停的 CNAME ' : '已存在其他 CNAME ') . $val,
            ];
        }

        if ($others) {
            $types = array_values(array_unique(array_column($others, 'Type')));
            $allActive = true;
            foreach ($others as $row) {
                if (isset($row['Status']) && (string)$row['Status'] === '0') $allActive = false;
            }
            // 只允许替换纯 A/AAAA，且必须全部处于启用态；MX/TXT/CAA/NS/SRV 等一律不动
            if ($this->cfg['overwrite'] === '1' && $allActive && !array_diff($types, ['A', 'AAAA'])) {
                return ['action' => 'replace', 'record_id' => null, 'records' => $others, 'message' => '将替换现有 ' . implode('/', $types) . ' 记录'];
            }
            return ['action' => 'conflict', 'record_id' => null, 'records' => $others, 'message' => '已存在 ' . implode('/', $types) . ' 记录，未覆盖'];
        }

        return ['action' => 'add', 'record_id' => null, 'message' => '待添加'];
    }

    private function lock()
    {
        if ($this->lockHandle) return true;
        $fp = @fopen($this->lockFile, 'c');
        if (!$fp) return false;
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        $this->lockHandle = $fp;
        return true;
    }

    private function unlock()
    {
        if (!$this->lockHandle) return;
        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }

    private function addLog($domain, $action, $data)
    {
        try {
            if (strlen($data) > 500) $data = substr($data, 0, 500);
            Db::name('log')->insert([
                'uid' => $this->uid > 0 ? $this->uid : 1000,
                'domain' => $domain,
                'action' => $action,
                'data' => $data,
                'addtime' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
        }
    }
}
