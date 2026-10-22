<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\DnsHelper;
use app\lib\NewDb;

/**
 * CF优选IP
 */
class OptimizeService
{
    private $ip_address = [];
    private $ip_errors = [];
    private $domain_rows = [];
    private $dns_models = [];
    private $add_num = 0;
    private $change_num = 0;
    private $del_num = 0;
    private $conn;

    public function __construct($ipAddress = null, $ipErrors = null, $conn = null)
    {
        if (is_array($ipAddress)) {
            $this->ip_address = $ipAddress;
        }
        if (is_array($ipErrors)) {
            $this->ip_errors = $ipErrors;
        }
        $this->conn = $conn;
    }

    public static function get_license($api, $key)
    {
        if ($api == 2) {
            throw new Exception('xingpingcn.top 接口免费使用，无需密钥，无积分限制');
        } elseif ($api == 1) {
            $url = 'https://api.hostmonit.com/get_license?license='.$key;
        } else {
            $url = 'https://www.wetest.vip/api/cf2dns/get_license?license='.$key;
        }
        $response = get_curl($url);
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);
        if (isset($arr['code']) && $arr['code'] == 200 && isset($arr['count'])) {
            return $arr['count'];
        } elseif (isset($arr['info'])) {
            throw new Exception('获取剩余请求次数失败，'.$arr['info']);
        } else {
            throw new Exception('获取剩余请求次数失败');
        }
    }

    public function get_ip_address($cdn_type = 1, $ip_type = 'v4')
    {
        $api = config_get('optimize_ip_api', 0);
        if ($api == 2) {
            return $this->get_ip_address_xingpingcn($ip_type);
        } elseif ($api == 1) {
            $url = 'https://api.hostmonit.com/get_optimization_ip';
        } else {
            $url = 'https://www.wetest.vip/api/cf2dns/';
            if ($cdn_type == 1) {
                $url .= 'get_cloudflare_ip';
            } elseif ($cdn_type == 2) {
                $url .= 'get_cloudfront_ip';
            } elseif ($cdn_type == 3) {
                $url .= 'get_gcore_ip';
            } elseif ($cdn_type == 4) {
                $url .= 'get_edgeone_ip';
            }
        }
        $params = [
            'key' => config_get('optimize_ip_key', 'o1zrmHAF'),
            'type' => $ip_type,
        ];
        $response = get_curl($url, json_encode($params), 0, 0, 0, 0, ['Content-Type' => 'application/json; charset=UTF-8']);
        if (!$response) throw new Exception('接口请求失败');
        $arr = json_decode($response, true);
        if (isset($arr['code']) && $arr['code'] == 200) {
            return $arr['info'];
        } elseif (isset($arr['info'])) {
            throw new Exception('获取优选IP数据失败，'.$arr['info']);
        } elseif (isset($arr['msg'])) {
            throw new Exception('获取优选IP数据失败，'.$arr['msg']);
        } else {
            throw new Exception('获取优选IP数据失败，原因未知');
        }
    }

    /**
     * 从 xingpingcn.top 获取优选IP数据
     * @param string $ip_type IP类型 v4/v6
     * @return array
     * @throws Exception
     */
    private function get_ip_address_xingpingcn($ip_type = 'v4')
    {
        if ($ip_type == 'v6') {
            throw new Exception('xingpingcn.top 接口暂不支持IPv6');
        }
        $proxy = config_get('optimize_ip_proxy', '');
        if (!empty($proxy)) {
            $proxy = trim($proxy);
            if (filter_var($proxy, FILTER_VALIDATE_URL) === false) {
                throw new Exception('无效的代理地址配置：URL 格式错误');
            }
            $scheme = parse_url($proxy, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'], true)) {
                throw new Exception('无效的代理地址配置：仅支持 http 和 https 协议');
            }
            $url = rtrim($proxy, '/') . '/xingpingcn/enhanced-FaaS-in-China/refs/heads/main/Cf.json';
        } else {
            $url = 'https://raw.githubusercontent.com/xingpingcn/enhanced-FaaS-in-China/refs/heads/main/Cf.json';
        }
        $response = get_curl($url);
        if (!$response) {
            throw new Exception('获取优选IP数据失败，网络请求失败，请检查网络连接或代理地址');
        }
        $arr = json_decode($response, true);
        if (isset($arr['Cf']['result'])) {
            $result = $arr['Cf']['result'];
            $info = [];
            // 转换格式：dianxin->CT, liantong->CU, yidong->CM, default->DEF
            if (isset($result['dianxin']) && is_array($result['dianxin'])) {
                $info['CT'] = array_map(function($ip) { return ['ip' => $ip]; }, $result['dianxin']);
            }
            if (isset($result['liantong']) && is_array($result['liantong'])) {
                $info['CU'] = array_map(function($ip) { return ['ip' => $ip]; }, $result['liantong']);
            }
            if (isset($result['yidong']) && is_array($result['yidong'])) {
                $info['CM'] = array_map(function($ip) { return ['ip' => $ip]; }, $result['yidong']);
            }
            // 不使用他的默认线路数据, 因为这真的是默认. 由后续逻辑自己决定是否把CT线路当DEF来用
            // if (isset($result['default']) && is_array($result['default'])) {
            //     $info['DEF'] = array_map(function($ip) { return ['ip' => $ip]; }, $result['default']);
            // }
            return $info;
        } else {
            throw new Exception('获取优选IP数据失败，接口返回数据格式错误');
        }
    }

    public function get_ip_address2($cdn_type = 1, $ip_type = 'v4')
    {
        $key = $cdn_type.'_'.$ip_type;
        if (isset($this->ip_errors[$key])) {
            throw new Exception($this->ip_errors[$key]);
        }
        if (!array_key_exists($key, $this->ip_address)) {
            try {
                $info = $this->get_ip_address($cdn_type, $ip_type);
                $res = [];
                if (isset($info['DEF'])) {
                    $res['DEF'] = $info['DEF'];
                }
                if (isset($info['CT'])) {
                    $res['CT'] = $info['CT'];
                }
                if (isset($info['CU'])) {
                    $res['CU'] = $info['CU'];
                }
                if (isset($info['CM'])) {
                    $res['CM'] = $info['CM'];
                }
                $this->ip_address[$key] = $res;
            } catch (\Throwable $e) {
                $this->ip_errors[$key] = $e->getMessage() ?: '获取优选IP数据失败';
                throw $e;
            }
        }
        return $this->ip_address[$key];
    }

    //批量执行优选任务
    public function execute()
    {
        $minute = config_get('optimize_ip_min', '30');
        $last = config_get('optimize_ip_time', null, true);
        if ($last && strtotime($last) > time() - $minute * 60) {
            return false;
        }

        $lock = $this->acquireExecutionLock();
        if (!$lock) {
            echo 'CF优选IP任务正在执行，跳过本次运行'."\n";
            return false;
        }

        try {
            $list = Db::name('optimizeip')->alias('T')
                ->leftJoin('domain D', 'T.did = D.id')
                ->leftJoin('account A', 'D.aid = A.id')
                ->where('T.active', 1)
                ->field('T.*,A.id aid,A.type provider')
                ->select()->toArray();
            if (count($list) == 0) {
                return false;
            }

            $concurrency = min($this->getConcurrency(), count($list));
            $accountConcurrency = min($concurrency, $this->getAccountConcurrency());
            echo '开始执行IP优选任务，共获取到'.count($list).'个待执行任务，并发数'.$concurrency.'，单账户并发数'.$accountConcurrency."\n";

            [$ipCache, $ipErrors] = $this->preloadIpAddress($list);
            if ($concurrency > 1 && $this->canRunConcurrently()) {
                $this->executeConcurrent($list, $ipCache, $ipErrors, $concurrency, $accountConcurrency);
            } else {
                if ($concurrency > 1) {
                    echo '未安装 Swoole 扩展，自动降级为串行执行'."\n";
                }
                foreach ($list as $row) {
                    $this->runTaskRow($row, $ipCache, $ipErrors, false);
                }
            }

            config_set('optimize_ip_time', date("Y-m-d H:i:s"));
            return true;
        } finally {
            $this->releaseExecutionLock($lock);
        }
    }

    private function getConcurrency()
    {
        return max(1, min(20, intval(config_get('optimize_ip_concurrency', 4))));
    }

    private function getAccountConcurrency()
    {
        return max(1, min(20, intval(config_get('optimize_ip_account_concurrency', 2))));
    }

    private function getRequestTimeout()
    {
        return max(5, min(300, intval(config_get('optimize_ip_timeout', 30))));
    }

    private function canRunConcurrently()
    {
        return extension_loaded('swoole')
            && class_exists('\Swoole\Coroutine\Channel')
            && function_exists('Swoole\Coroutine\run');
    }

    private function preloadIpAddress(array $list)
    {
        $preloader = new self();
        $previousTimeout = set_http_request_timeout($this->getRequestTimeout());
        try {
            foreach ($list as $row) {
                foreach (explode(',', (string)$row['ip_type']) as $ipType) {
                    $ipType = trim($ipType);
                    if ($ipType === '') {
                        continue;
                    }
                    try {
                        $preloader->get_ip_address2(intval($row['cdn_type']), $ipType);
                    } catch (\Throwable $e) {
                        // 错误会按接口类型缓存，任务执行时直接复用，避免重复请求。
                    }
                }
            }
            return [$preloader->ip_address, $preloader->ip_errors];
        } finally {
            set_http_request_timeout($previousTimeout);
        }
    }

    private function executeConcurrent(array $list, array $ipCache, array $ipErrors, int $concurrency, int $accountConcurrency)
    {
        $total = count($list);
        $nextIndex = 0;
        $done = new \Swoole\Coroutine\Channel($concurrency);
        $accountChannels = [];
        foreach ($list as $row) {
            $accountKey = (string)($row['aid'] ?? '0');
            if (isset($accountChannels[$accountKey])) {
                continue;
            }
            $channel = new \Swoole\Coroutine\Channel($accountConcurrency);
            for ($i = 0; $i < $accountConcurrency; $i++) {
                $channel->push(true);
            }
            $accountChannels[$accountKey] = $channel;
        }

        $worker = function () use (&$nextIndex, $total, $list, $ipCache, $ipErrors, $done, $accountChannels) {
            try {
                while (true) {
                    $index = $nextIndex++;
                    if ($index >= $total) {
                        break;
                    }
                    $accountKey = (string)($list[$index]['aid'] ?? '0');
                    $accountChannels[$accountKey]->pop();
                    try {
                        $this->runTaskRow($list[$index], $ipCache, $ipErrors, true);
                    } finally {
                        $accountChannels[$accountKey]->push(true);
                    }
                }
            } finally {
                $done->push(true);
            }
        };

        $runner = function () use ($worker, $concurrency, $done) {
            for ($i = 0; $i < $concurrency; $i++) {
                \Swoole\Coroutine::create($worker);
            }
            for ($i = 0; $i < $concurrency; $i++) {
                $done->pop();
            }
        };

        if (\Swoole\Coroutine::getCid() >= 0) {
            $runner();
            return;
        }

        if (defined('SWOOLE_HOOK_ALL')) {
            $hookFlags = SWOOLE_HOOK_ALL;
            if (defined('SWOOLE_HOOK_PROC')) {
                $hookFlags &= ~SWOOLE_HOOK_PROC;
            }
            @\Swoole\Coroutine::set(['hook_flags' => $hookFlags]);
        }
        \Swoole\Coroutine\run($runner);
    }

    private function runTaskRow(array $row, array $ipCache, array $ipErrors, bool $usePool)
    {
        $conn = null;
        $broken = false;
        $taskService = new self($ipCache, $ipErrors);

        try {
            if ($usePool) {
                $conn = NewDb::pool();
                $taskService = new self($ipCache, $ipErrors, $conn);
            }
            $result = $taskService->execute_one($row);
            $taskService->updateTaskStatus($row['id'], ['status' => 1, 'errmsg' => null, 'updatetime' => date('Y-m-d H:i:s')]);
            echo '优选任务'.$row['id'].'执行成功：'.$result."\n";
        } catch (\Throwable $e) {
            try {
                $taskService->updateTaskStatus($row['id'], ['status' => 2, 'errmsg' => $e->getMessage(), 'updatetime' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $dbError) {
                $broken = true;
                echo '优选任务'.$row['id'].'状态写入失败：'.$dbError->getMessage()."\n";
            }
            echo '优选任务'.$row['id'].'执行失败：'.$e->getMessage()."\n";
        } finally {
            if ($conn) {
                NewDb::release($conn, $broken);
            }
        }
    }

    private function updateTaskStatus($id, array $data)
    {
        if ($this->conn) {
            $this->conn->name('optimizeip')->where('id', $id)->update($data);
        } else {
            Db::name('optimizeip')->where('id', $id)->update($data);
        }
    }

    private function acquireExecutionLock()
    {
        $handle = @fopen(app()->getRuntimePath().'optimizeip.lock', 'c');
        if (!$handle) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        return $handle;
    }

    private function releaseExecutionLock($handle)
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    //执行单个优选任务
    public function execute_one($row)
    {
        $previousTimeout = set_http_request_timeout($this->getRequestTimeout());
        try {
            return $this->executeOneTask($row);
        } finally {
            set_http_request_timeout($previousTimeout);
        }
    }

    private function executeOneTask($row)
    {
        $this->add_num = 0;
        $this->change_num = 0;
        $this->del_num = 0;
        $ip_types = explode(',', $row['ip_type']);
        foreach ($ip_types as $ip_type) {
            if (empty($ip_type)) {
                continue;
            }

            $drow = $this->getTaskDomain($row['did']);
            if (!$drow) {
                throw new Exception('域名不存在（ID：'.$row['did'].'）');
            }
            if (!isset(DnsHelper::$line_name[$drow['type']])) {
                throw new Exception('不支持的DNS服务商');
            }

            $info = $this->get_ip_address2($row['cdn_type'], $ip_type);

            $dns = $this->getDnsModel($drow);
            if (!$dns) {
                throw new Exception('DNS模块不存在');
            }
            $domainRecords = $dns->getSubDomainRecords($row['rr'], 1, 100);
            if (!$domainRecords) {
                throw new Exception('获取记录列表失败，'.$dns->getError());
            }

            if ($row['type'] == 1 && isset($info['DEF']) && !empty($info['DEF'])) {
                $row['type'] = 0;
            }

            foreach ($info as $line => $iplist) {
                if (empty($iplist)) {
                    continue;
                }
                $record_num = $row['recordnum'];
                $get_ips = array_column($iplist, 'ip');
                if ($drow['type'] == 'huawei') {
                    $get_ips = array_values(array_unique($get_ips));
                    sort($get_ips);
                    $get_ips = array_slice($get_ips, 0, $row['recordnum']);
                    $get_ips = [implode(',', $get_ips)];
                    $record_num = 1;
                }
                if ($row['type'] == 1 && $line == 'CT') {
                    $line = 'DEF';
                }
                if (!isset(DnsHelper::$line_name[$drow['type']][$line])) {
                    continue;
                }
                $line_name = DnsHelper::$line_name[$drow['type']][$line];
                $this->process_dns_line($dns, $row, $domainRecords['list'], $record_num, $get_ips, $line_name, $ip_type);
            }
        }

        return '成功添加'.$this->add_num.'条记录，修改'.$this->change_num.'条记录，删除'.$this->del_num.'条记录';
    }

    private function getTaskDomain($did)
    {
        $did = intval($did);
        if (array_key_exists($did, $this->domain_rows)) {
            return $this->domain_rows[$did];
        }
        $query = $this->conn ? $this->conn->name('domain') : Db::name('domain');
        $drow = $query->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->where('A.id', $did)
            ->field('A.*,B.type,B.config account_config')
            ->find();
        if ($drow) {
            $drow['config'] = $drow['account_config'] ?? '';
        }
        $this->domain_rows[$did] = $drow ?: null;
        return $this->domain_rows[$did];
    }

    private function getDnsModel($drow)
    {
        $did = intval($drow['id']);
        if (array_key_exists($did, $this->dns_models)) {
            return $this->dns_models[$did];
        }
        $accountConfig = json_decode($drow['config'] ?? '', true);
        if (!is_array($accountConfig)) {
            $accountConfig = [];
        }
        $accountConfig['timeout'] = $this->getRequestTimeout();
        $drow['config'] = json_encode($accountConfig);
        $dns = DnsHelper::getModel2($drow);
        $this->dns_models[$did] = $dns ?: null;
        return $this->dns_models[$did];
    }

    //处理单个线路的解析记录
    private function process_dns_line($dns, $row, $record_list, $record_num, $get_ips, $line_name, $ip_type)
    {
        $records = array_filter($record_list, function ($v) use ($line_name) {
            return $v['Line'] == $line_name;
        });

        //删除CNAME记录
        $cname_records = array_filter($records, function ($v) {
            return $v['Type'] == 'CNAME';
        });
        if (!empty($cname_records)) {
            foreach ($cname_records as $record) {
                $dns->deleteDomainRecord($record['RecordId']);
            }
        }

        //处理A/AAAA记录
        $ip_records = array_filter($records, function ($v) use ($ip_type) {
            return $v['Type'] == ($ip_type == 'v6' ? 'AAAA' : 'A');
        });

        if (!empty($ip_records) && is_array($ip_records[array_key_first($ip_records)]['Value'])) { //处理华为云记录
            foreach ($ip_records as &$ip_record) {
                sort($ip_record['Value']);
                $ip_record['Value'] = implode(',', $ip_record['Value']);
            }
        }

        $desired_ips = array_values(array_unique(array_filter(array_map('strval', $get_ips), function ($ip) {
            return trim($ip) !== '';
        })));
        $desired_ips = array_slice($desired_ips, 0, max(1, intval($record_num)));
        if (empty($desired_ips)) return;

        $desired_map = array_fill_keys($desired_ips, true);
        $kept_records = [];
        $extra_records = [];

        foreach ($ip_records as $record) {
            $value = trim((string)$record['Value']);
            if (isset($desired_map[$value]) && !isset($kept_records[$value])) {
                $kept_records[$value] = $record;
            } else {
                $extra_records[] = $record;
            }
        }

        $missing_ips = array_values(array_filter($desired_ips, function ($ip) use ($kept_records) {
            return !isset($kept_records[$ip]);
        }));

        foreach ($extra_records as $record) {
            if (!empty($missing_ips)) {
                $new_ip = array_shift($missing_ips);
                $res = $dns->updateDomainRecord($record['RecordId'], $row['rr'], $ip_type == 'v6' ? 'AAAA' : 'A', $new_ip, $line_name, $row['ttl']);
                if (!$res) {
                    throw new Exception('修改解析失败，'.$dns->getError());
                }
                $this->change_num++;
                continue;
            }

            $res = $dns->deleteDomainRecord($record['RecordId']);
            if (!$res) {
                throw new Exception('删除解析失败，'.$dns->getError());
            }
            $this->del_num++;
        }

        foreach ($missing_ips as $add_ip) {
            $res = $dns->addDomainRecord($row['rr'], $ip_type == 'v6' ? 'AAAA' : 'A', $add_ip, $line_name, $row['ttl']);
            if (!$res) {
                throw new Exception('添加解析失败，'.$dns->getError());
            }
            $this->add_num++;
        }
    }
}
