<?php

namespace app\lib\dns;

use app\lib\DnsInterface;

class qingcloud implements DnsInterface
{
    private $access_key_id;
    private $secret_access_key;
    private $baseUrl = 'http://api.routewize.com';
    private $error;
    private $domain;
    private $domainid;
    private $proxy;

    public function __construct($config)
    {
        $this->access_key_id = $config['access_key_id'];
        $this->secret_access_key = $config['secret_access_key'];
        $this->domain = $config['domain'];
        $this->domainid = $config['domainid'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function getError()
    {
        return $this->error;
    }

    public function check()
    {
        if ($this->getDomainList() != false) {
            return true;
        }
        return false;
    }

    //获取域名列表
    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $offset = ($PageNumber - 1) * $PageSize;
        $param = ['offset' => $offset, 'limit' => $PageSize];
        if (!empty($KeyWord)) {
            $param['zone_name'] = $KeyWord;
        }
        $data = $this->execute('GET', '/v1/user/zones', $param);
        if ($data) {
            $list = [];
            foreach ($data['zones'] as $row) {
                $list[] = [
                    'DomainId' => $row['zone_name'],
                    'Domain' => rtrim($row['zone_name'], '.'),
                    'RecordCount' => 0,
                ];
            }
            return ['total' => $data['total_count'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        if ($SubDomain) {
            return $this->getHostRecords($SubDomain);
        }
        $offset = ($PageNumber - 1) * $PageSize;
        $param = ['zone_name' => $this->domainid, 'offset' => $offset, 'limit' => $PageSize];
        if (!isNullOrEmpty($KeyWord)) {
            $param['search_word'] = $KeyWord;
        }
        $data = $this->execute('GET', '/v1/dns/host/', $param);
        if ($data) {
            $list = [];
            foreach ($data['domains'] as $row) {
                $name = substr($row['domain_name'], 0, -(strlen($row['zone_name']) + 1));
                if ($name == '') $name = '@';
                $list[] = [
                    'RecordId' => $row['domain_name'],
                    'Domain' => $this->domain,
                    'Name' => $name,
                    'Type' => null,
                    'Value' => null,
                    'Line' => null,
                    'TTL' => null,
                    'MX' => null,
                    'Status' => $row['status'] == 'enabled' ? '0' : '1',
                    'Weight' => null,
                    'Remark' => $row['description'],
                    'UpdateTime' => $row['create_time'],
                    'Count' => $row['count'],
                ];
            }
            return ['total' => $data['total_count'], 'list' => $list];
        }
        return false;
    }

    private function getHostRecords($SubDomain)
    {
        $param = ['zone_name' => $this->domainid, 'domain_name' => $SubDomain];
        $data = $this->execute('GET', '/v1/dns/host_info/', $param);
        if ($data) {
            $list = [];
            foreach ($data['records'] as $record) {
                $name = substr($record['domain_name'], 0, -(strlen($record['zone_name']) + 1));
                if ($name == '') $name = '@';
                foreach ($record['record'] as $record_group) {
                    foreach ($record_group['data'] as $row) {
                        $mx = null;
                        if ($record['rd_type'] == 'MX') {
                            $value = explode(' ', $row['value'], 2);
                            $row['value'] = isset($value[1]) ? $value[1] : '';
                            $mx = intval($value[0]);
                        }
                        if ($record['rd_type'] == 'TXT') {
                            $row['value'] = trim($row['value'], '"');
                        }
                        $list[] = [
                            'RecordId' => $record['domain_record_id'].'_'.$row['record_value_id'],
                            'Domain' => $record['domain_name'],
                            'Name' => $name,
                            'Type' => $record['rd_type'],
                            'Mode' => $record['mode'],
                            'Value' => $row['value'],
                            'Line' => $record['view_id'],
                            'TTL' => $record['ttl'],
                            'MX' => $mx,
                            'Status' => $row['status'] == 1 ? '1' : '0',
                            'Weight' => $record_group['weight'] > 0 ? $record_group['weight'] : null,
                            'Remark' => null,
                            'UpdateTime' => $record['create_time'],
                        ];
                    }
                }
            }
            return ['total' => $data['total_count'], 'list' => $list];
        }
        return false;
    }

    //获取子域名解析记录列表
    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        $SubDomain = $this->getHost($SubDomain);
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    //获取解析记录详细信息
    public function getDomainRecordInfo($RecordId)
    {
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $mode = input('post.mode', '1');

        if ($Type == 'MX') {
            $Value = intval($MX).' '.$Value;
        } elseif ($Type == 'TXT') {
            $Value = '"'.$Value.'"';
        }

        $values = [];
        foreach (explode(',', $Value) as $val) {
            $values[] = ['value' => trim($val), 'status' => 1];
        }
        if (($Type == 'A' || $Type == 'CNAME') && $mode == '3') $Weight = intval($Weight);
        else $Weight = 0;
        $record = [['weight' => $Weight, 'values' => $values]];

        $param = ['zone_name' => $this->domainid, 'domain_name' => $Name, 'view_id' => intval($Line), 'type' => $Type, 'ttl' => intval($TTL), 'record' => json_encode($record), 'mode' => intval($mode), 'auto_merge' => 2];
        $data = $this->execute('POST', '/v1/record/', $param);
        return is_array($data) ? $data['domain_record_id'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $mode = input('post.mode', '1');

        if ($Type == 'MX') {
            $Value = intval($MX).' '.$Value;
        } elseif ($Type == 'TXT') {
            $Value = '"'.$Value.'"';
        }

        $recordId = explode('_', $RecordId);
        $domain_record_id = $recordId[0];
        $record_value_id = $recordId[1];
        $data = $this->execute('GET', '/v1/dr_id/'.$domain_record_id);
        if (!$data) return false;

        if (($Type == 'A' || $Type == 'CNAME') && $mode == '3') $Weight = intval($Weight);
        else $Weight = 0;
        $record = [];
        foreach ($data['data']['record'] as $record_group) {
            $values = [];
            $flag = false;
            foreach ($record_group['data'] as $row) {
                if ($row['record_value_id'] == $record_value_id) {
                    $row['value'] = $Value;
                    $flag = true;
                }
                $values[] = ['value' => $row['value'], 'status' => $row['status']];
            }
            if (count($values) > 0) {
                $record[] = ['weight' => $flag ? $Weight : $record_group['weight'], 'values' => $values];
            }
        }

        $param = ['zone_name' => $this->domainid, 'domain_name' => $Name, 'view_id' => intval($Line), 'type' => $Type, 'ttl' => intval($TTL), 'record' => json_encode($record), 'mode' => intval($mode)];
        $data = $this->execute('POST', '/v1/dr_id/'.$domain_record_id, $param);
        return $data !== false;
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        $param = ['zone_name' => $this->domainid, 'domain_name' => $RecordId, 'description' => $Remark];
        $data = $this->execute('POST', '/v1/dns/host/', $param);
        return $data !== false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        if (strpos($RecordId, $this->domainid) !== false) {
            $param = ['domain_names' => json_encode([$RecordId]), 'zone_name' => $this->domainid];
            $data = $this->execute('DELETE', '/v1/domain/', $param);
            return $data !== false;
        }

        $recordId = explode('_', $RecordId);
        $domain_record_id = $recordId[0];
        $record_value_id = $recordId[1];
        $data = $this->execute('GET', '/v1/dr_id/'.$domain_record_id);
        if (!$data) return false;

        $record = [];
        foreach ($data['data']['record'] as $record_group) {
            $values = [];
            foreach ($record_group['data'] as $row) {
                if ($row['record_value_id'] == $record_value_id) {
                    continue;
                }
                $values[] = ['value' => $row['value'], 'status' => $row['status']];
            }
            if (count($values) > 0) {
                $record[] = ['weight' => $record_group['weight'], 'values' => $values];
            }
        }

        if (count($record) == 0) {
            $param = ['ids' => json_encode([$domain_record_id]), 'target' => 'record', 'action' => 'delete'];
            $data = $this->execute('POST', '/v1/change_record_status/', $param);
            return $data !== false;
        }

        $name = substr($data['data']['domain_name'], 0, -(strlen($data['data']['zone_name']) + 1));
        if ($name == '') $name = '@';
        $param = ['zone_name' => $this->domainid, 'domain_name' => $name, 'view_id' => $data['data']['view_id'], 'type' => $data['data']['rd_type'], 'ttl' => $data['data']['ttl'], 'record' => json_encode($record), 'mode' => $data['data']['mode']];
        $data = $this->execute('POST', '/v1/dr_id/'.$domain_record_id, $param);
        return $data !== false;
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $recordId = explode('_', $RecordId);
        $record_value_id = $recordId[1];
        $param = ['ids' => json_encode([$record_value_id]), 'target' => 'value', 'action' => $Status == '0' ? 'stop' : 'enable'];
        $data = $this->execute('POST', '/v1/change_record_status/', $param);
        return $data !== false;
    }

    //获取解析记录操作日志
    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    //获取解析线路列表
    public function getRecordLine()
    {
        $param = ['zone_name' => $this->domainid, 'type' => 'GET_FULL'];
        $data = $this->execute('GET', '/v1/zone/view/', $param);
        if ($data) {
            $list = [];
            foreach ($data['zone_views'] as $row) {
                if ($row['name'] == '*') $row['name'] = '默认';
                $list[$row['id']] = ['name' => $row['name'], 'parent' => null];
            }
            return $list;
        }
        return false;
    }

    //获取域名信息
    public function getDomainInfo()
    {
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return 60;
    }

    public function addDomain($Domain)
    {
        $param = ['zone_name' => $Domain];
        $data = $this->execute('POST', '/v1/zone/', $param);
        if ($data) {
            return ['id' => $data['zone_name'], 'name' => $Domain];
        }
        return false;
    }

    private function getHost($Name)
    {
        if ($Name == '@' || $Name == '') $Name = '';
        else $Name .= '.';
        $Name .= $this->domain . '.';
        return $Name;
    }

    private function execute($method, $path, $params = null)
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $string_to_sign = $method."\n".$date."\n".$path;
        if($method == 'GET' && $params){
            ksort($params);
            $string_to_sign .= '?'.http_build_query($params);
        }
        $signature = base64_encode(hash_hmac('sha256', $string_to_sign, $this->secret_access_key, true));
        $authorization = 'QC-HMAC-SHA256 '.$this->access_key_id.':'.$signature;
        $header = [
            'Authorization' => $authorization,
            'Date' => $date,
        ];
        if ($method == 'POST' || $method == 'PUT' || $method == 'DELETE') {
            $header['Content-Type'] = 'application/json; charset=utf-8';
            $response = $this->curl($method, $path, $header, json_encode($params));
        } else {
            if ($params) {
                $path .= '?'.http_build_query($params);
            }
            $response = $this->curl($method, $path, $header);
        }
        $arr = json_decode($response['body'], true);
        if (isset($arr['code']) && $arr['code'] == 0 || isset($arr['domains']) || $method == 'DELETE' && $response['code'] == 204) {
            return $arr;
        } elseif(isset($arr['message'])) {
            $this->setError($arr['message']);
            return false;
        } elseif(isset($arr['msg'])) {
            $this->setError($arr['msg']);
            return false;
        } else {
            $this->setError('返回数据解析失败');
            return false;
        }
    }

    private function curl($method, $path, $header, $body = null)
    {
        $url = $this->baseUrl . $path;
        try {
            $response = http_request($url, $body, null, null, $header, $this->proxy, $method);
        } catch (\Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
        return $response;
    }

    private function setError($message)
    {
        $this->error = $message;
        //file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
    }
}
