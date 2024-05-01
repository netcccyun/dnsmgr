<?php

namespace app\lib;

use Exception;
use think\facade\Db;

class OptimizeService
{
    private static $line_name = [
        'aliyun' => ['DEF'=>'default', 'CT'=>'telecom', 'CU'=>'unicom', 'CM'=>'mobile', 'AB'=>'oversea'],
        'dnspod' => ['DEF'=>'0', 'CT'=>'10=0', 'CU'=>'10=1', 'CM'=>'10=3', 'AB'=>'3=0'],
        'huawei' => ['DEF'=>'default_view', 'CT'=>'Dianxin', 'CU'=>'Liantong', 'CM'=>'Yidong', 'AB'=>'Abroad'],
        'west' => ['DEF'=>'', 'CT'=>'LTEL', 'CU'=>'LCNC', 'CM'=>'LMOB', 'AB'=>'LFOR'],
        'dnsla' => ['DEF'=>'', 'CT'=>'84613316902921216', 'CU'=>'84613316923892736', 'CM'=>'84613316953252864', 'AB'=>''],
    ];

    private $ip_address = [];
    private $add_num = 0;
    private $change_num = 0;
    private $del_num = 0;

    public static function get_license($api, $key){
        if($api == 1){
            $url = 'https://api.hostmonit.com/get_license?license='.$key;
        }else{
            $url = 'https://monitor.gacjie.cn/api/client/get_account_integral?license='.$key;
        }
        $response = get_curl($url);
        $arr = json_decode($response, true);
        if(isset($arr['code']) && $arr['code'] == 200 && isset($arr['count'])){
            return $arr['count'];
        }elseif(isset($arr['info'])){
            throw new Exception('获取剩余请求次数失败，'.$arr['info']);
        }else{
            throw new Exception('获取剩余请求次数失败');
        }
    }

    public function get_ip_address($cdn_type = 1, $ip_type = 'v4'){
        $api = config_get('optimize_ip_api', 0);
        if($api == 1){
            $url = 'https://api.hostmonit.com/get_optimization_ip';
        }else{
            $url = 'https://monitor.gacjie.cn/api/client/get_ip_address';
        }
        $params = [
            'key' => config_get('optimize_ip_key', 'o1zrmHAF'),
            'type' => $ip_type,
        ];
        if($api == 0){
            $params['cdn_server'] = $cdn_type;
        }
        $response = get_curl($url, json_encode($params), 0, 0, 0, 0, 0, ['Content-Type: application/json; charset=UTF-8']);
        $arr = json_decode($response, true);
        if(isset($arr['code']) && $arr['code'] == 200){
            return $arr['info'];
        }elseif(isset($arr['info'])){
            throw new Exception('获取优选IP数据失败，'.$arr['info']);
        }elseif(isset($arr['msg'])){
            throw new Exception('获取优选IP数据失败，'.$arr['msg']);
        }else{
            throw new Exception('获取优选IP数据失败，原因未知');
        }
    }

    public function get_ip_address2($cdn_type = 1, $ip_type = 'v4'){
        $key = $cdn_type.'_'.$ip_type;
        if(!isset($this->ip_address[$key])){
            $info = $this->get_ip_address($cdn_type, $ip_type);
            $res = [];
            if(isset($info['DEF'])) $res['DEF'] = $info['DEF'];
            if(isset($info['CT'])) $res['CT'] = $info['CT'];
            if(isset($info['CU'])) $res['CU'] = $info['CU'];
            if(isset($info['CM'])) $res['CM'] = $info['CM'];
            $this->ip_address[$key] = $res;
        }
        return $this->ip_address[$key];
    }

    //批量执行优选任务
    public function execute(){
        $list = Db::name('optimizeip')->where('active', 1)->select();
        echo '开始执行IP优选任务，共获取到'.count($list).'个待执行任务'."\n";
        foreach($list as $row){
            try{
                $result = $this->execute_one($row);
                Db::name('optimizeip')->where('id', $row['id'])->update(['status' => 1, 'errmsg' => null, 'updatetime' => date('Y-m-d H:i:s')]);
                echo '优选任务'.$row['id'].'执行成功：'.$result."\n";
            }catch(Exception $e){
                Db::name('optimizeip')->where('id', $row['id'])->update(['status' => 2, 'errmsg' => $e->getMessage(), 'updatetime' => date('Y-m-d H:i:s')]);
                echo '优选任务'.$row['id'].'执行失败：'.$e->getMessage()."\n";
            }
        }
    }

    //执行单个优选任务
    public function execute_one($row){
        $this->add_num = 0;
        $this->change_num = 0;
        $this->del_num = 0;
        $ip_types = explode(',', $row['ip_type']);
        foreach($ip_types as $ip_type){
            if(empty($ip_type)) continue;

            $drow = Db::name('domain')->alias('A')->join('account B','A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type,B.ak,B.sk,B.ext')->find();
            if(!$drow){
                throw new Exception('域名不存在（ID：'.$row['did'].'）');
            }
            if(!isset(self::$line_name[$drow['type']])){
                throw new Exception('不支持的DNS服务商');
            }

            $info = $this->get_ip_address2($row['cdn_type'], $ip_type);

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            $domainRecords = $dns->getSubDomainRecords($row['rr'], 1, 100);
            if(!$domainRecords){
                throw new Exception('获取记录列表失败，'.$dns->getError());
            }

            if($row['type'] == 1 && isset($info['DEF']) && !empty($info['DEF'])) $row['type'] = 0;

            foreach($info as $line=>$iplist){
                if(empty($iplist)) continue;
                $get_ips = array_column($iplist, 'ip');
                if($drow['type']=='huawei') {sort($get_ips); $get_ips = [implode(',',$get_ips)]; $row['recordnum'] = 1;}
                if($row['type'] == 1 && $line == 'CT') $line = 'DEF';
                $line_name = self::$line_name[$drow['type']][$line];
                $this->process_dns_line($dns, $row, $domainRecords['list'], $get_ips, $line_name, $ip_type);
            }
        }

        return '成功添加'.$this->add_num.'条记录，修改'.$this->change_num.'条记录，删除'.$this->del_num.'条记录';
    }

    //处理单个线路的解析记录
    private function process_dns_line($dns, $row, $record_list, $get_ips, $line_name, $ip_type){
        $record_num = $row['recordnum'];
        $records = array_filter($record_list, function($v) use($line_name){
            return $v['Line'] == $line_name;
        });

        //删除CNAME记录
        $cname_records = array_filter($records, function($v){
            return $v['Type'] == 'CNAME';
        });
        if(!empty($cname_records)){
            foreach($cname_records as $record){
                $dns->deleteDomainRecord($record['RecordId']);
            }
        }
        
        //处理A/AAAA记录
        $ip_records = array_filter($records, function($v) use ($ip_type){
            return $v['Type'] == ($ip_type == 'v6' ? 'AAAA' : 'A');
        });

        if(!empty($ip_records) && is_array($ip_records[array_key_first($ip_records)]['Value'])){ //处理华为云记录
            foreach($ip_records as &$ip_record){
                sort($ip_record['Value']);
                $ip_record['Value'] = implode(',', $ip_record['Value']);
            }
        }

        $exist_ips = array_column($ip_records, 'Value');
        $add_ips = array_diff($get_ips, $exist_ips);
        $del_ips = array_diff($exist_ips, $get_ips);
        $correct_ips = array_diff($exist_ips, $del_ips);
        $correct_count = count($correct_ips);
        if(!empty($del_ips)){
            foreach($ip_records as $record){
                if(in_array($record['Value'], $del_ips)){
                    $add_ip = array_pop($add_ips);
                    if($add_ip){
                        $res = $dns->updateDomainRecord($record['RecordId'], $row['rr'], $ip_type == 'v6' ? 'AAAA' : 'A', $add_ip, $line_name, $row['ttl']);
                        if(!$res){
                            throw new Exception('修改解析失败，'.$dns->getError());
                        }
                        $this->change_num++;
                        $correct_count++;
                    }else{
                        $res = $dns->deleteDomainRecord($record['RecordId']);
                        if(!$res){
                            throw new Exception('删除解析失败，'.$dns->getError());
                        }
                        $this->del_num++;
                    }
                }
            }
        }
        if($correct_count < $record_num && !empty($add_ips)){
            foreach($add_ips as $add_ip){
                $res = $dns->addDomainRecord($row['rr'], $ip_type == 'v6' ? 'AAAA' : 'A', $add_ip, $line_name, $row['ttl']);
                if(!$res){
                    throw new Exception('添加解析失败，'.$dns->getError());
                }
                $this->add_num++;
                $correct_count++;
                if($correct_count >= $record_num) break;
            }
        }
    }
}