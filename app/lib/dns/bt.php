<?php

namespace app\lib\dns;

use app\lib\DnsInterface;

class bt implements DnsInterface
{
    private $accountId;
    private $accessKey;
    private $secretKey;
    private $baseUrl = 'https://dmp.bt.cn';
    private $error;
    private $domain;
    private $domainid;
    private $domainType;
    private $proxy;

    public function __construct($config)
    {
        $this->accountId = $config['AccountID'];
        $this->accessKey = $config['AccessKey'];
        $this->secretKey = $config['SecretKey'];
        $this->domain = $config['domain'];
        if ($config['domainid']) {
            $a = explode('|', $config['domainid']);
            $this->domainid = intval($a[0]);
            $this->domainType = isset($a[1]) ? intval($a[1]) : 1;
        }
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
        $param = ['p' => $PageNumber, 'rows' => $PageSize, 'keyword' => $KeyWord];
        $data = $this->execute('/api/v1/dns/manage/list_domains', $param);
        if ($data) {
            $list = [];
            foreach ($data['data'] as $row) {
                $list[] = [
                    'DomainId' => $row['local_id'] . '|' . $row['domain_type'],
                    'Domain' => $row['full_domain'],
                    'RecordCount' => $row['record_count'],
                ];
            }
            return ['total' => $data['total'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $param = ['domain_id' => $this->domainid, 'domain_type' => $this->domainType, 'p' => $PageNumber, 'rows' => $PageSize];
        if (!isNullOrEmpty($SubDomain)) {
            $param['searchKey'] = 'record';
            $param['searchValue'] = $SubDomain;
        } elseif (!isNullOrEmpty($KeyWord)) {
            $param['searchKey'] = 'record';
            $param['searchValue'] = $KeyWord;
        } elseif (!isNullOrEmpty($Value)) {
            $param['searchKey'] = 'value';
            $param['searchValue'] = $Value;
        } elseif (!isNullOrEmpty($Type)) {
            $param['searchKey'] = 'type';
            $param['searchValue'] = $Type;
        } elseif (!isNullOrEmpty($Status)) {
            $param['searchKey'] = 'state';
            $param['searchValue'] = $Status == '0' ? '1' : '0';
        } elseif (!isNullOrEmpty($Line)) {
            $param['searchKey'] = 'line';
            $param['searchValue'] = $Line;
        }
        $data = $this->execute('/api/v1/dns/record/list', $param);
        if ($data) {
            $list = [];
            foreach ($data['data'] as $row) {
                $list[] = [
                    'RecordId' => $row['record_id'],
                    'Domain' => $this->domain,
                    'Name' => $row['record'],
                    'Type' => $row['type'],
                    'Value' => $row['value'],
                    'Line' => $row['viewID'],
                    'TTL' => $row['TTL'],
                    'MX' => $row['MX'],
                    'Status' => $row['state'] == 1 ? '0' : '1',
                    'Weight' => $row['MX'],
                    'Remark' => $row['remark'],
                    'UpdateTime' => date('Y-m-d H:i:s', strtotime($row['created_at'])),
                ];
            }
            return ['total' => $data['count'], 'list' => $list];
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
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = ['domain_id' => $this->domainid, 'domain_type' => $this->domainType, 'type' => $Type, 'record' => $Name, 'value' => $Value, 'ttl' => intval($TTL), 'view_id' => intval($Line), 'remark' => $Remark];
        if (!$Weight) $Weight = 1;
        if ($Type == 'MX') $param['mx'] = intval($MX);
        else $param['mx'] = intval($Weight);
        $data = $this->execute('/api/v1/dns/record/create', $param);
        return $data !== false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = ['record_id' => $RecordId, 'domain_id' => $this->domainid, 'domain_type' => $this->domainType, 'type' => $Type, 'record' => $Name, 'value' => $Value, 'ttl' => intval($TTL), 'view_id' => intval($Line), 'remark' => $Remark];
        if (!$Weight) $Weight = 1;
        if ($Type == 'MX') $param['mx'] = intval($MX);
        else $param['mx'] = intval($Weight);
        $data = $this->execute('/api/v1/dns/record/update', $param);
        return $data !== false;
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $param = ['id' => $RecordId, 'domain_id' => $this->domainid, 'domain_type' => $this->domainType];
        $data = $this->execute('/api/v1/dns/record/delete', $param);
        return $data !== false;
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $param = ['record_id' => $RecordId, 'domain_id' => $this->domainid, 'domain_type' => $this->domainType];
        $data = $this->execute($Status == '0' ? '/api/v1/dns/record/pause' : '/api/v1/dns/record/start', $param);
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
        $param = [];
        $data = $this->execute('/api/v1/dns/record/get_views', $param);
        if ($data) {
            $list = [];
            $this->processLineList($list, $data, null);
            return $list;
        }
        return false;
    }

    private function processLineList(&$list, $line_list, $parent)
    {
        foreach ($line_list as $row) {
            if ($row['free'] && !isset($list[$row['viewId']])) {
                $list[$row['viewId']] = ['name' => $row['name'], 'parent' => $parent];
                if ($row['children']) {
                    $this->processLineList($list, $row['children'], $row['viewId']);
                }
            }
        }
    }

    //获取域名信息
    public function getDomainInfo()
    {
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return 300;
    }

    public function addDomain($Domain)
    {
        $param = ['full_domain' => $Domain];
        $data = $this->execute('/api/v1/dns/manage/add_external_domain', $param);
        if ($data) {
            return ['id' => $data['domain_id'], 'name' => $data['full_domain']];
        }
        return false;
    }

    private function execute($path, $params)
    {
        $method = 'POST';
        $timestamp = (string)time();
        $body = json_encode($params);
        $signingString = implode("\n", [
            $this->accountId,
            $timestamp,
            $method,
            $path,
            $body
        ]);
        $signature = hash_hmac('sha256', $signingString, $this->secretKey);
        $headers = [
            'Content-Type' => 'application/json',
            'X-Account-ID' => $this->accountId,
            'X-Access-Key' => $this->accessKey,
            'X-Timestamp' => $timestamp,
            'X-Signature' => $signature
        ];
        $response = $this->curl($method, $path, $headers, $body);
        if (!$response) {
            return false;
        }
        $arr = json_decode($response, true);
        if ($arr) {
            if ($arr['code'] == 0) {
                return $arr['data'];
            } else {
                $this->setError($arr['msg']);
                return false;
            }
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
        return $response['body'];
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}
