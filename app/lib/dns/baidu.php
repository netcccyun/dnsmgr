<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use app\lib\client\BaiduCloud;
use Exception;

class baidu implements DnsInterface
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint = "dns.baidubce.com";
    private $error;
    private $domain;
    private $domainid;
    private BaiduCloud $client;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['ak'];
        $this->SecretAccessKey = $config['sk'];
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new BaiduCloud($this->AccessKeyId, $this->SecretAccessKey, $this->endpoint, $proxy);
        $this->domain = $config['domain'];
        $this->domainid = $config['domainid'];
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
        $query = ['name' => $KeyWord];
        $data = $this->send_reuqest('GET', '/v1/dns/zone', $query);
        if ($data) {
            $list = [];
            foreach ($data['zones'] as $row) {
                $list[] = [
                    'DomainId' => $row['id'],
                    'Domain' => rtrim($row['name'], '.'),
                    'RecordCount' => 0,
                ];
            }
            return ['total' => count($list), 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $query = [];
        if (!isNullOrEmpty($SubDomain)) {
            $SubDomain = strtolower($SubDomain);
            $query['rr'] = $SubDomain;
        }
        $data = $this->send_reuqest('GET', '/v1/dns/zone/'.$this->domain.'/record', $query);
        if ($data) {
            $list = [];
            foreach ($data['records'] as $row) {
                $list[] = [
                    'RecordId' => $row['id'],
                    'Domain' => $this->domain,
                    'Name' => $row['rr'],
                    'Type' => $row['type'],
                    'Value' => $row['value'],
                    'Line' => $row['line'],
                    'TTL' => $row['ttl'],
                    'MX' => $row['priority'],
                    'Status' => $row['status'] == 'running' ? '1' : '0',
                    'Weight' => null,
                    'Remark' => $row['description'],
                    'UpdateTime' => null,
                ];
            }
            if (!isNullOrEmpty($SubDomain)) {
                $list = array_values(array_filter($list, function ($v) use ($SubDomain) {
                    return $v['Name'] == $SubDomain;
                }));
            } else {
                if (!isNullOrEmpty($KeyWord)) {
                    $list = array_values(array_filter($list, function ($v) use ($KeyWord) {
                        return strpos($v['Name'], $KeyWord) !== false || strpos($v['Value'], $KeyWord) !== false;
                    }));
                }
                if (!isNullOrEmpty($Value)) {
                    $list = array_values(array_filter($list, function ($v) use ($Value) {
                        return $v['Value'] == $Value;
                    }));
                }
                if (!isNullOrEmpty($Type)) {
                    $list = array_values(array_filter($list, function ($v) use ($Type) {
                        return $v['Type'] == $Type;
                    }));
                }
                if (!isNullOrEmpty($Status)) {
                    $list = array_values(array_filter($list, function ($v) use ($Status) {
                        return $v['Status'] == $Status;
                    }));
                }
            }
            return ['total' => count($list), 'list' => $list];
        }
        return false;
    }

    //获取子域名解析记录列表
    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        if ($SubDomain == '') $SubDomain = '@';
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    //获取解析记录详细信息
    public function getDomainRecordInfo($RecordId)
    {
        $query = ['id' => $RecordId];
        $data = $this->send_reuqest('GET', '/v1/dns/zone/'.$this->domain.'/record', $query);
        if ($data && !empty($data['records'])) {
            $data = $data['records'][0];
            return [
                'RecordId' => $data['id'],
                'Domain' => rtrim($data['zone_name'], '.'),
                'Name' => str_replace('.'.$data['zone_name'], '', $data['name']),
                'Type' => $data['type'],
                'Value' => $data['value'],
                'Line' => $data['line'],
                'TTL' => $data['ttl'],
                'MX' => $data['priority'],
                'Status' => $data['status'] == 'running' ? '1' : '0',
                'Weight' => null,
                'Remark' => $data['description'],
                'UpdateTime' => null,
            ];
        }
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $params = ['rr' => $Name, 'type' => $this->convertType($Type), 'value' => $Value, 'line' => $Line, 'ttl' => intval($TTL), 'description' => $Remark];
        if ($Type == 'MX') $params['priority'] = intval($MX);
        $query = ['clientToken' => getSid()];
        return $this->send_reuqest('POST', '/v1/dns/zone/'.$this->domain.'/record', $query, $params);
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $params = ['rr' => $Name, 'type' => $this->convertType($Type), 'value' => $Value, 'line' => $Line, 'ttl' => intval($TTL), 'description' => $Remark];
        if ($Type == 'MX') $params['priority'] = intval($MX);
        $query = ['clientToken' => getSid()];
        return $this->send_reuqest('PUT', '/v1/dns/zone/'.$this->domain.'/record/'.$RecordId, $query, $params);
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $query = ['clientToken' => getSid()];
        return $this->send_reuqest('DELETE', '/v1/dns/zone/'.$this->domain.'/record/'.$RecordId, $query);
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $Status = $Status == '1' ? 'enable' : 'disable';
        $query = [$Status => '', 'clientToken' => getSid()];
        return $this->send_reuqest('PUT', '/v1/dns/zone/'.$this->domain.'/record/'.$RecordId, $query);
    }

    //获取解析记录操作日志
    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    //获取解析线路列表
    public function getRecordLine()
    {
        return [
            'default' => ['name' => '默认', 'parent' => null],
            'ct' => ['name' => '电信', 'parent' => null],
            'cnc' => ['name' => '联通', 'parent' => null],
            'cmnet' => ['name' => '移动', 'parent' => null],
            'edu' => ['name' => '教育网', 'parent' => null],
            'search' => ['name' => '搜索引擎(百度)', 'parent' => null],
        ];
    }

    //获取域名概览信息
    public function getDomainInfo()
    {
        $res = $this->getDomainList($this->domain);
        if ($res && !empty($res['list'])) {
            return $res['list'][0];
        }
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return false;
    }

    private function convertType($type)
    {
        return $type;
    }

    private function send_reuqest($method, $path, $query = null, $params = null)
    {
        try{
            return $this->client->request($method, $path, $query, $params);
        }catch(Exception $e){
            $this->setError($e->getMessage());
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
        //file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
    }
}
