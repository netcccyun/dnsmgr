<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\DnsHelper;

/**
 * CF优选IP
 */
class OptimizeService
{
    private $ip_address = [];
    private $add_num = 0;
    private $change_num = 0;
    private $del_num = 0;

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
        if ($response === '') {
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
        if (!isset($this->ip_address[$key])) {
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
        $list = Db::name('optimizeip')->where('active', 1)->select();
        if (count($list) == 0) {
            return false;
        }
        echo '开始执行IP优选任务，共获取到'.count($list).'个待执行任务'."\n";
        foreach ($list as $row) {
            try {
                $result = $this->execute_one($row);
                Db::name('optimizeip')->where('id', $row['id'])->update(['status' => 1, 'errmsg' => null, 'updatetime' => date('Y-m-d H:i:s')]);
                echo '优选任务'.$row['id'].'执行成功：'.$result."\n";
            } catch (Exception $e) {
                Db::name('optimizeip')->where('id', $row['id'])->update(['status' => 2, 'errmsg' => $e->getMessage(), 'updatetime' => date('Y-m-d H:i:s')]);
                echo '优选任务'.$row['id'].'执行失败：'.$e->getMessage()."\n";
            }
        }
        config_set('optimize_ip_time', date("Y-m-d H:i:s"));
        return true;
    }

    //执行单个优选任务
    public function execute_one($row)
    {
        $this->add_num = 0;
        $this->change_num = 0;
        $this->del_num = 0;
        $ip_types = explode(',', $row['ip_type']);
        foreach ($ip_types as $ip_type) {
            if (empty($ip_type)) {
                continue;
            }

            $drow = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type')->find();
            if (!$drow) {
                throw new Exception('域名不存在（ID：'.$row['did'].'）');
            }
            if (!isset(DnsHelper::$line_name[$drow['type']])) {
                throw new Exception('不支持的DNS服务商');
            }

            $info = $this->get_ip_address2($row['cdn_type'], $ip_type);

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
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

        $exist_ips = array_column($ip_records, 'Value');
        $add_ips = array_diff($get_ips, $exist_ips);
        $del_ips = array_diff($exist_ips, $get_ips);
        $correct_ips = array_diff($exist_ips, $del_ips);
        $correct_count = count($correct_ips);
        if (!empty($del_ips)) {
            foreach ($ip_records as $record) {
                if (in_array($record['Value'], $del_ips)) {
                    $add_ip = array_pop($add_ips);
                    if ($add_ip) {
                        $res = $dns->updateDomainRecord($record['RecordId'], $row['rr'], $ip_type == 'v6' ? 'AAAA' : 'A', $add_ip, $line_name, $row['ttl']);
                        if (!$res) {
                            throw new Exception('修改解析失败，'.$dns->getError());
                        }
                        $this->change_num++;
                        $correct_count++;
                    } else {
                        $res = $dns->deleteDomainRecord($record['RecordId']);
                        if (!$res) {
                            throw new Exception('删除解析失败，'.$dns->getError());
                        }
                        $this->del_num++;
                    }
                }
            }
        }
        if ($correct_count < $record_num && !empty($add_ips)) {
            foreach ($add_ips as $add_ip) {
                $res = $dns->addDomainRecord($row['rr'], $ip_type == 'v6' ? 'AAAA' : 'A', $add_ip, $line_name, $row['ttl']);
                if (!$res) {
                    throw new Exception('添加解析失败，'.$dns->getError());
                }
                $this->add_num++;
                $correct_count++;
                if ($correct_count >= $record_num) {
                    break;
                }
            }
        }
    }
}
