<?php

namespace app\controller;

use app\BaseController;
use app\service\CloudflareEnhanceService;
use Exception;
use think\facade\Db;
use think\facade\View;

class Optimizeipbatch extends BaseController
{
    private const MAX_BATCH_SIZE = 500;

    public function index()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');

        $rows = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->where('B.type', 'cloudflare')
            ->field('A.id,A.name,A.thirdid')
            ->order('A.name', 'asc')
            ->select()
            ->toArray();

        $cfDomains = [];
        foreach ($rows as $row) {
            if (trim((string)($row['thirdid'] ?? '')) === '') continue;
            $cfDomains[] = [
                'id' => intval($row['id']),
                'name' => trim((string)$row['name']),
            ];
        }

        View::assign('cfDomains', $cfDomains);
        View::assign('optimizeIpApi', config_get('optimize_ip_api', 0));
        return View::fetch('optimizeip/batch');
    }

    public function pull()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);

        try {
            $context = $this->getCloudflareContext(input('post.cf_domain_id/d'));
            $managedDomains = $this->getManagedDomains();
            $existing = $this->getExistingTaskMap();

            $rows = [];
            foreach ($context['service']->listCustomHostnames($context['domain']['thirdid']) as $item) {
                $hostname = $this->normalizeHostname($item['hostname'] ?? '');
                if ($hostname === '') continue;
                $match = $this->matchManagedDomain($managedDomains, $hostname);
                $taskKey = $match ? ($match['did'] . '|' . $match['rr']) : '';
                $rows[] = [
                    'hostname' => $hostname,
                    'hostname_id' => trim((string)($item['id'] ?? '')),
                    'matched' => $match !== null,
                    'did' => $match['did'] ?? 0,
                    'rr' => $match['rr'] ?? '',
                    'domain_name' => $match['domain_name'] ?? '',
                    'dns_type_name' => $match['dns_type_name'] ?? '',
                    'exists' => $taskKey !== '' && isset($existing[$taskKey]),
                ];
            }

            usort($rows, function ($a, $b) {
                return strcmp($a['hostname'], $b['hostname']);
            });

            return json([
                'code' => 0,
                'data' => [
                    'rows' => $rows,
                    'total' => count($rows),
                    'matched' => count(array_filter($rows, function ($row) { return $row['matched']; })),
                ],
            ]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function create()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);

        try {
            $context = $this->getCloudflareContext(input('post.cf_domain_id/d'));
            $hostnames = input('post.hostnames/a', []);
            if (!is_array($hostnames) || empty($hostnames)) {
                throw new Exception('请选择要创建任务的自定义主机名');
            }
            if (count($hostnames) > self::MAX_BATCH_SIZE) {
                throw new Exception('单次最多创建 ' . self::MAX_BATCH_SIZE . ' 个任务');
            }

            $type = input('post.type/d', 0) === 1 ? 1 : 0;
            $ipTypes = input('post.ip_type/a', ['v4']);
            $ipTypes = array_values(array_intersect(['v4', 'v6'], array_map('strval', (array)$ipTypes)));
            if (empty($ipTypes)) {
                throw new Exception('请至少选择一种解析IP类型');
            }
            if (intval(config_get('optimize_ip_api', 0)) === 2 && in_array('v6', $ipTypes, true)) {
                throw new Exception('当前优选IP接口不支持IPv6');
            }

            $recordnum = input('post.recordnum/d', 2);
            $ttl = input('post.ttl/d', 600);
            if ($recordnum < 1 || $recordnum > 50) {
                throw new Exception('每线路解析数量必须在 1-50 之间');
            }
            if ($ttl < 1 || $ttl > 3600) {
                throw new Exception('TTL 必须在 1-3600 之间');
            }

            $remark = trim((string)input('post.remark', '', 'trim'));
            if ($remark === '') $remark = 'CF自定义主机名批量任务';
            $active = input('post.active/d', 1) === 1 ? 1 : 0;
            $ipType = implode(',', $ipTypes);

            $allowedHostnames = [];
            foreach ($context['service']->listCustomHostnames($context['domain']['thirdid']) as $item) {
                $hostname = $this->normalizeHostname($item['hostname'] ?? '');
                if ($hostname !== '') $allowedHostnames[$hostname] = true;
            }

            $managedDomains = $this->getManagedDomains();
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];
            $seen = [];

            Db::startTrans();
            try {
                foreach ($hostnames as $hostnameValue) {
                    $hostname = $this->normalizeHostname($hostnameValue);
                    if ($hostname === '' || isset($seen[$hostname])) continue;
                    $seen[$hostname] = true;

                    if (!isset($allowedHostnames[$hostname])) {
                        $skipped++;
                        $errors[] = $hostname . '：不属于所选 Cloudflare 自定义主机名';
                        continue;
                    }

                    $match = $this->matchManagedDomain($managedDomains, $hostname);
                    if (!$match) {
                        $skipped++;
                        $errors[] = $hostname . '：未找到可写入的非 Cloudflare 解析域名';
                        continue;
                    }

                    $task = [
                        'did' => $match['did'],
                        'rr' => $match['rr'],
                        'type' => $type,
                        'ip_type' => $ipType,
                        'cdn_type' => 1,
                        'recordnum' => $recordnum,
                        'ttl' => $ttl,
                        'remark' => mb_substr($remark, 0, 100),
                        'active' => $active,
                        'status' => 0,
                        'errmsg' => null,
                    ];

                    $existing = Db::name('optimizeip')
                        ->where('did', $match['did'])
                        ->where('rr', $match['rr'])
                        ->find();
                    if ($existing) {
                        Db::name('optimizeip')->where('id', $existing['id'])->update($task);
                        $updated++;
                    } else {
                        $task['addtime'] = date('Y-m-d H:i:s');
                        Db::name('optimizeip')->insert($task);
                        $created++;
                    }
                }
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                throw $e;
            }

            $msg = '批量任务处理完成：新建 ' . $created . ' 个，更新 ' . $updated . ' 个';
            if ($skipped > 0) $msg .= '，跳过 ' . $skipped . ' 个';
            return json([
                'code' => 0,
                'msg' => $msg,
                'data' => [
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'errors' => $errors,
                ],
            ]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function getCloudflareContext(int $domainId): array
    {
        if ($domainId <= 0) throw new Exception('请选择 Cloudflare 自定义主机名所属域名');

        $row = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->where('A.id', $domainId)
            ->field('A.*,B.type,B.config account_config')
            ->find();
        if (!$row) throw new Exception('Cloudflare 域名不存在');
        if (($row['type'] ?? '') !== 'cloudflare') throw new Exception('所选域名不是 Cloudflare 账户');
        if (trim((string)($row['thirdid'] ?? '')) === '') throw new Exception('当前域名缺少 Cloudflare Zone ID');

        $config = json_decode($row['account_config'] ?? '', true);
        if (!is_array($config)) $config = [];

        return [
            'domain' => $row,
            'service' => new CloudflareEnhanceService($config),
        ];
    }

    private function getManagedDomains(): array
    {
        $rows = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->where('B.type', '<>', 'cloudflare')
            ->field('A.id,A.name,A.aid,B.type,B.name account_name')
            ->select()
            ->toArray();

        $domains = [];
        foreach ($rows as $row) {
            $name = $this->normalizeHostname($row['name'] ?? '');
            if ($name === '') continue;
            $dnsType = trim((string)($row['type'] ?? ''));
            $domains[] = [
                'did' => intval($row['id']),
                'domain_name' => $name,
                'dns_type' => $dnsType,
                'dns_type_name' => $this->formatDnsTypeName($dnsType),
            ];
        }
        return $domains;
    }

    private function getExistingTaskMap(): array
    {
        $map = [];
        foreach (Db::name('optimizeip')->field('id,did,rr')->select()->toArray() as $row) {
            $map[intval($row['did']) . '|' . trim((string)$row['rr'])] = intval($row['id']);
        }
        return $map;
    }

    private function matchManagedDomain(array $domains, string $hostname): ?array
    {
        $matchHostname = preg_replace('/^\*\./', '', $hostname);
        $best = null;
        $bestLength = -1;

        foreach ($domains as $domain) {
            $domainName = $domain['domain_name'];
            $rr = null;
            if ($matchHostname === $domainName) {
                $rr = '@';
            } elseif (str_ends_with($matchHostname, '.' . $domainName)) {
                $rr = substr($matchHostname, 0, -strlen($domainName) - 1);
                if (str_starts_with($hostname, '*.')) {
                    $rr = $rr === '@' ? '*' : ('*.' . $rr);
                }
            }
            if ($rr === null) continue;

            if (strlen($domainName) > $bestLength) {
                $bestLength = strlen($domainName);
                $best = [
                    'did' => $domain['did'],
                    'rr' => $rr,
                    'domain_name' => $domainName,
                    'dns_type_name' => $domain['dns_type_name'],
                ];
            }
        }
        return $best;
    }

    private function normalizeHostname($hostname): string
    {
        $hostname = trim((string)$hostname);
        if ($hostname === '') return '';
        $hostname = convertDomainToAscii(rtrim($hostname, '.'));
        return strtolower($hostname);
    }

    private function formatDnsTypeName(string $type): string
    {
        $list = \app\lib\DnsHelper::getList();
        return $list[$type]['name'] ?? ($type !== '' ? $type : '-');
    }
}
