<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

class namesilo implements DnsInterface
{
    private $apikey;
    private $baseUrl = 'https://www.namesilo.com/api/';
    private $version = '1';
    private $error;
    private $domain;

    function __construct($config)
    {
        $this->apikey = $config['sk'];
        $this->domain = $config['domain'];
    }

    public function getError()
    {
        return $this->error;
    }

    public function check()
    {
        if ($this->getDomainList() !== false) {
            return true;
        }
        return false;
    }

    //获取域名列表
    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $param = ['page' => $PageNumber, 'pageSize' => $PageSize];
        $data = $this->send_reuqest('listDomains', $param);
        if ($data) {
            $list = [];
            if($data['domains']){
                foreach ($data['domains'] as $row) {
                    $list[] = [
                        'DomainId' => $row['domain'],
                        'Domain' => $row['domain'],
                        'RecordCount' => 0,
                    ];
                }
            }
            return ['total' => $data['pager']['total'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $param = ['domain' => $this->domain];
        $data = $this->send_reuqest('dnsListRecords', $param);
        if ($data) {
            $list = [];
            foreach ($data['resource_record'] as $row) {
                $name = $row['host'] == $this->domain ? '@' : str_replace('.'.$this->domain, '', $row['host']);
                $list[] = [
                    'RecordId' => $row['record_id'],
                    'Domain' => $this->domain,
                    'Name' => $name,
                    'Type' => $row['type'],
                    'Value' => $row['value'],
                    'Line' => 'default',
                    'TTL' => $row['ttl'],
                    'MX' => isset($row['distance']) ? $row['distance'] : null,
                    'Status' => '1',
                    'Weight' => null,
                    'Remark' => null,
                    'UpdateTime' => null,
                ];
            }
            if(!empty($SubDomain)){
                $list = array_values(array_filter($list, function($v) use ($SubDomain){
                    return $v['Name'] == $SubDomain;
                }));
            }else{
                if(!empty($KeyWord)){
                    $list = array_values(array_filter($list, function($v) use ($KeyWord){
                        return strpos($v['Name'], $KeyWord) !== false || strpos($v['Value'], $KeyWord) !== false;
                    }));
                }
                if(!empty($Value)){
                    $list = array_values(array_filter($list, function($v) use ($Value){
                        return $v['Value'] == $Value;
                    }));
                }
                if(!empty($Type)){
                    $list = array_values(array_filter($list, function($v) use ($Type){
                        return $v['Type'] == $Type;
                    }));
                }
            }
            return ['total' => count($data['resource_record']), 'list' => $list];
        }
        return false;
    }

    //获取子域名解析记录列表
    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
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
        $param = ['domain' => $this->domain, 'rrtype' => $Type, 'rrhost' => $Name, 'rrvalue' => $Value, 'rrttl' => $TTL];
        if ($Type == 'MX') $param['rrdistance'] = intval($MX);
        $data = $this->send_reuqest('dnsAddRecord', $param);
        return is_array($data) ? $data['record_id'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = ['domain' => $this->domain, 'rrid' => $RecordId, 'rrtype' => $Type, 'rrhost' => $Name, 'rrvalue' => $Value, 'rrttl' => $TTL];
        if ($Type == 'MX') $param['rrdistance'] = intval($MX);
        $data = $this->send_reuqest('dnsUpdateRecord', $param);
        return is_array($data);
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $param = ['domain' => $this->domain, 'rrid' => $RecordId];
        $data = $this->send_reuqest('dnsDeleteRecord', $param);
        return is_array($data);
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        return false;
    }

    //获取解析记录操作日志
    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    //获取解析线路列表
    public function getRecordLine()
    {
        return ['default' => ['name' => '默认', 'parent' => null]];
    }

    //获取域名信息
    public function getDomainInfo()
    {
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return false;
    }

    private function send_reuqest($operation, $param = null)
    {
        $url = $this->baseUrl . $operation;

        $params = [
            'version' => $this->version,
            'type' => 'json',
            'key' => $this->apikey,
        ];
        if($param){
            $params = array_merge($params, $param);
        }

        $url .= '?' . http_build_query($params);

        try{
            $response = curl_client($url);
        }catch(Exception $e){
            $this->setError($e->getMessage());
            return false;
        }

        $arr = json_decode($response['body'], true);
        if (isset($arr['reply']['code'])) {
            if ($arr['reply']['code'] == 300) {
                return $arr['reply'];
            } else {
                $this->setError(isset($arr['reply']['detail']) ? $arr['reply']['detail'] : '未知错误');
                return false;
            }
        } else {
            $this->setError($response['body']);
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
        //file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
    }
}
