<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use app\lib\client\TencentCloud;
use Exception;

class tencenteo implements DnsInterface
{
    private $SecretId;
    private $SecretKey;
    private $endpoint = "teo.tencentcloudapi.com";
    private $service = "teo";
    private $version = "2022-09-01";
    private $error;
    private $domain;
    private $domainid;
    private $domainInfo;
    private TencentCloud $client;

    public function __construct($config)
    {
        $this->SecretId = $config['SecretId'];
        $this->SecretKey = $config['SecretKey'];
        if (isset($config['site_type']) && $config['site_type'] == 'intl') {
            $this->endpoint = "teo.intl.tencentcloudapi.com";
        }
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new TencentCloud($this->SecretId, $this->SecretKey, $this->endpoint, $this->service, $this->version, null, $proxy);
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
        $action = 'DescribeZones';
        $offset = ($PageNumber - 1) * $PageSize;
        $filters = [['Name' => 'zone-type', 'Values' => ['full']]];
        if (!isNullOrEmpty($KeyWord)) {
            $filters[] = ['Name' => 'zone-name', 'Values' => [$KeyWord]];
        }
        $param = ['Offset' => $offset, 'Limit' => $PageSize, 'Filters' => $filters];
        $data = $this->send_request($action, $param);
        if ($data) {
            $list = [];
            foreach ($data['Zones'] as $row) {
                $list[] = [
                    'DomainId' => $row['ZoneId'],
                    'Domain' => $row['ZoneName'],
                    'RecordCount' => 0,
                ];
            }
            return ['total' => $data['TotalCount'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $offset = ($PageNumber - 1) * $PageSize;
        $action = 'DescribeDnsRecords';
        $filters = [];
        if (!isNullOrEmpty($SubDomain)) {
            $name = $SubDomain == '@' ? $this->domain : $SubDomain . '.' . $this->domain;
            $filters[] = ['Name' => 'name', 'Values' => [$name]];
        } elseif (!isNullOrEmpty($KeyWord)) {
            $name = $KeyWord == '@' ? $this->domain : $KeyWord . '.' . $this->domain;
            $filters[] = ['Name' => 'name', 'Values' => [$name]];
        }
        if (!isNullOrEmpty($Value)) {
            $filters[] = ['Name' => 'content', 'Values' => [$Value], 'Fuzzy' => true];
        }
        if (!isNullOrEmpty($Type)) {
            $filters[] = ['Name' => 'type', 'Values' => [$Type]];
        }
        $param = ['ZoneId' => $this->domainid, 'Offset' => $offset, 'Limit' => $PageSize, 'Filters' => $filters];
        $data = $this->send_request($action, $param);
        if ($data) {
            $list = [];
            foreach ($data['DnsRecords'] as $row) {
                $name = substr($row['Name'], 0, - (strlen($this->domain) + 1));
                if ($name == '') $name = '@';
                $list[] = [
                    'RecordId' => $row['RecordId'],
                    'Domain' => $this->domain,
                    'Name' => $name,
                    'Type' => $row['Type'],
                    'Value' => $row['Content'],
                    'Line' => $row['Location'],
                    'TTL' => $row['TTL'],
                    'MX' => $row['Priority'],
                    'Status' => $row['Status'] == 'enable' ? '1' : '0',
                    'Weight' => $row['Weight'] == -1 ? null : $row['Weight'],
                    'Remark' => null,
                    'UpdateTime' => $row['ModifiedOn'],
                ];
            }
            return ['total' => $data['TotalCount'], 'list' => $list];
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
        $action = 'DescribeDnsRecords';
        $param = ['ZoneId' => $this->domainid, 'Filters' => [['Name' => 'id', 'Values' => [$RecordId]]]];
        $data = $this->send_request($action, $param);
        if ($data) {
            $row = $data['DnsRecords'][0];
            $name = substr($row['Name'], 0, - (strlen($this->domain) + 1));
            if ($name == '') $name = '@';
            return [
                'RecordId' => $row['RecordId'],
                'Domain' => $this->domain,
                'Name' => $name,
                'Type' => $row['Type'],
                'Value' => $row['Content'],
                'Line' => $row['Location'],
                'TTL' => $row['TTL'],
                'MX' => $row['Priority'],
                'Status' => $row['Status'] == 'enable' ? '1' : '0',
                'Weight' => $row['Weight'] == -1 ? null : $row['Weight'],
                'Remark' => null,
                'UpdateTime' => $row['ModifiedOn'],
            ];
        }
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $action = 'CreateDnsRecord';
        if ($Name == '@') {
            $Name = $this->domain;
        } else {
            $Name = $Name . '.' . $this->domain;
        }
        $param = ['ZoneId' => $this->domainid, 'Name' => $Name, 'Type' => $Type, 'Content' => $Value, 'Location' => $Line, 'TTL' => intval($TTL), 'Weight' => empty($Weight) ? -1 : intval($Weight)];
        if ($Type == 'MX') $param['Priority'] = intval($MX);
        $data = $this->send_request($action, $param);
        return is_array($data) ? $data['RecordId'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $action = 'ModifyDnsRecord';
        if ($Name == '@') {
            $Name = $this->domain;
        } else {
            $Name = $Name . '.' . $this->domain;
        }
        $param = ['ZoneId' => $this->domainid, 'DnsRecordId' => $RecordId, 'Name' => $Name, 'Type' => $Type, 'Content' => $Value, 'Location' => $Line, 'TTL' => intval($TTL), 'Weight' => empty($Weight) ? -1 : intval($Weight)];
        if ($Type == 'MX') $param['Priority'] = intval($MX);
        $data = $this->send_request($action, $param);
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
        $action = 'DeleteDnsRecords';
        $param = ['ZoneId' => $this->domainid, 'RecordIds' => [$RecordId]];
        $data = $this->send_request($action, $param);
        return is_array($data);
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $action = 'ModifyDnsRecordsStatus';
        $param = ['ZoneId' => $this->domainid];
        if ($Status == '1') $param['RecordsToEnable'] = [$RecordId];
        else $param['RecordsToDisable'] = [$RecordId];
        $data = $this->send_request($action, $param);
        return is_array($data);
    }

    //获取解析记录操作日志
    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    //获取解析线路列表
    public function getRecordLine()
    {
        return ['Default' => ['name' => '默认', 'parent' => null]];
    }

    //获取域名概览信息
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
        return false;
    }

    private function send_request($action, $param)
    {
        try{
            return $this->client->request($action, $param);
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
