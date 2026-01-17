<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use app\lib\client\Aliyun as AliyunClient;
use Exception;

class aliyunesa implements DnsInterface
{
    private $AccessKeyId;
    private $AccessKeySecret;
    private $Endpoint = 'esa.cn-hangzhou.aliyuncs.com'; //API接入域名
    private $Version = '2024-09-10'; //API版本号
    private $error;
    private $domain;
    private $domainid;
    private AliyunClient $client;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->AccessKeySecret = $config['AccessKeySecret'];
        if (!empty($config['region'])) {
            $this->Endpoint = 'esa.'.$config['region'].'.aliyuncs.com';
        }
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $this->Endpoint, $this->Version, $proxy);
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
        $param = ['Action' => 'ListSites', 'SiteName' => $KeyWord, 'PageNumber' => $PageNumber, 'PageSize' => $PageSize, 'AccessType' => 'NS'];
        $data = $this->request($param, 'GET', true);
        if ($data) {
            $list = [];
            foreach ($data['Sites'] as $row) {
                $list[] = [
                    'DomainId' => $row['SiteId'],
                    'Domain' => $row['SiteName'],
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
        $param = ['Action' => 'ListRecords', 'SiteId' => $this->domainid, 'PageNumber' => $PageNumber, 'PageSize' => $PageSize];
        if (!isNullOrEmpty($SubDomain)) {
            $RecordName = $SubDomain == '@' ? $this->domain : $SubDomain . '.' . $this->domain;
            $param += ['RecordName' => $RecordName];
        } elseif (!isNullOrEmpty($KeyWord)) {
            $RecordName = $KeyWord == '@' ? $this->domain : $KeyWord . '.' . $this->domain;
            $param += ['RecordName' => $RecordName];
        }
        if (!isNullOrEmpty($Type)) {
            if ($Type == 'A' || $Type == 'AAAA') $Type = 'A/AAAA';
            $param += ['Type' => $Type];
        }
        if (!isNullOrEmpty($Line)) {
            $param += ['Proxied' => $Line == '1' ? 'true' : 'false'];
        }
        $data = $this->request($param, 'GET', true);
        if ($data) {
            $list = [];
            foreach ($data['Records'] as $row) {
                $name = substr($row['RecordName'], 0, - (strlen($this->domain) + 1));
                if ($name == '') $name = '@';
                $value = $row['Data']['Value'];
                if ($row['RecordType'] == 'CAA') $value = $row['Data']['Flag'] . ' ' . $row['Data']['Tag'] . ' ' . $row['Data']['Value'];
                else if ($row['RecordType'] == 'SRV') $value = $row['Data']['Priority'] . ' ' . $row['Data']['Weight'] . ' ' . $row['Data']['Port'] . ' ' . $row['Data']['Value'];
                if ($row['RecordType'] == 'A/AAAA') {
                    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $row['RecordType'] = 'A';
                    } elseif (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $row['RecordType'] = 'AAAA';
                    }
                }
                $list[] = [
                    'RecordId' => $row['RecordId'],
                    'Domain' => $this->domain,
                    'Name' => $name,
                    'Type' => $row['RecordType'],
                    'Value' => $value,
                    'Line' => $row['Proxied'] ? '1' : '0',
                    'TTL' => $row['Ttl'],
                    'MX' => isset($row['Data']['Priority']) ? $row['Data']['Priority'] : null,
                    'Status' => '1',
                    'Weight' => null,
                    'Remark' => isset($row['Comment']) ? $row['Comment'] : null,
                    'UpdateTime' => isset($row['UpdateTime']) ? date('Y-m-d H:i:s', strtotime($row['UpdateTime'])) : null,
                ];
            }
            return ['total' => $data['TotalCount'], 'list' => $list];
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
        $param = ['Action' => 'GetRecord', 'RecordId' => $RecordId];
        $data = $this->request($param, 'GET', true);
        if ($data) {
            $row = $data['RecordModel'];
            $name = substr($row['RecordName'], 0, - (strlen($this->domain) + 1));
            if ($name == '') $name = '@';
            $value = $row['Data']['Value'];
            if ($row['RecordType'] == 'CAA') $value = $row['Data']['Flag'] . ' ' . $row['Data']['Tag'] . ' ' . $row['Data']['Value'];
            else if ($row['RecordType'] == 'SRV') $value = $row['Data']['Priority'] . ' ' . $row['Data']['Weight'] . ' ' . $row['Data']['Port'] . ' ' . $row['Data']['Value'];
            if ($row['RecordType'] == 'A/AAAA') {
                if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $row['RecordType'] = 'A';
                } elseif (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $row['RecordType'] = 'AAAA';
                }
            }
            return [
                'RecordId' => $row['RecordId'],
                'Domain' => $this->domain,
                'Name' => $name,
                'Type' => $row['RecordType'],
                'Value' => $value,
                'Line' => $row['Proxied'] ? '1' : '0',
                'TTL' => $row['Ttl'],
                'MX' => isset($row['Data']['Priority']) ? $row['Data']['Priority'] : null,
                'Status' => '1',
                'Weight' => null,
                'Remark' => isset($row['Comment']) ? $row['Comment'] : null,
                'UpdateTime' => isset($row['UpdateTime']) ? date('Y-m-d H:i:s', strtotime($row['UpdateTime'])) : null,
            ];
        }
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = null, $Weight = null, $Remark = null)
    {
        if ($Name == '@') {
            $Name = $this->domain;
        } else {
            $Name = $Name . '.' . $this->domain;
        }
        if ($Type == 'A' || $Type == 'AAAA') $Type = 'A/AAAA';
        $data = ['Value' => $Value];
        if ($Type == 'CAA') {
            list($flag, $tag, $val) = explode(' ', $Value, 3);
            $data = ['Flag' => intval($flag), 'Tag' => $tag, 'Value' => $val];
        } elseif ($Type == 'SRV') {
            list($priority, $weight, $port, $val) = explode(' ', $Value, 4);
            $data = ['Priority' => intval($priority), 'Weight' => intval($weight), 'Port' => intval($port), 'Value' => $val];
        } elseif ($Type == 'MX') {
            $data['Priority'] = intval($MX);
        }
        $param = ['Action' => 'CreateRecord', 'SiteId' => $this->domainid, 'RecordName' => $Name, 'Type' => $Type, 'Proxied' => $Line == '1' ? 'true' : 'false', 'Ttl' => intval($TTL), 'Data' => json_encode($data), 'Comment' => $Remark];
        if ($Line == '1') $param['BizName'] = 'web';
        $data = $this->request($param, 'POST', true);
        if ($data) {
            return $data['RecordId'];
        }
        return false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = null, $Weight = null, $Remark = null)
    {
        if ($Name == '@') {
            $Name = $this->domain;
        } else {
            $Name = $Name . '.' . $this->domain;
        }
        if ($Type == 'A' || $Type == 'AAAA') $Type = 'A/AAAA';
        $data = ['Value' => $Value];
        if ($Type == 'CAA') {
            list($flag, $tag, $val) = explode(' ', $Value, 3);
            $data = ['Flag' => intval($flag), 'Tag' => $tag, 'Value' => $val];
        } elseif ($Type == 'SRV') {
            list($priority, $weight, $port, $val) = explode(' ', $Value, 4);
            $data = ['Priority' => intval($priority), 'Weight' => intval($weight), 'Port' => intval($port), 'Value' => $val];
        } elseif ($Type == 'MX') {
            $data['Priority'] = intval($MX);
        }
        $param = ['Action' => 'UpdateRecord', 'RecordId' => $RecordId, 'Type' => $Type, 'Proxied' => $Line == '1' ? 'true' : 'false', 'Ttl' => intval($TTL), 'Data' => json_encode($data), 'Comment' => $Remark];
        if ($Line == '1') $param['BizName'] = 'web';
        return $this->request($param, 'POST');
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $param = ['Action' => 'DeleteRecord', 'RecordId' => $RecordId];
        return $this->request($param, 'POST');
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
        return ['0' => ['name' => '仅DNS', 'parent' => null], '1' => ['name' => '已代理', 'parent' => null]];
    }

    //获取域名信息
    public function getDomainInfo()
    {
        $param = ['Action' => 'GetSite', 'SiteId' => $this->domainid];
        $data = $this->request($param, 'GET', true);
        if ($data) {
            return $data;
        }
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return 1;
    }

    public function addDomain($Domain)
    {
        return false;
    }

    private function request($param, $method, $returnData = false)
    {
        if (empty($this->AccessKeyId) || empty($this->AccessKeySecret)) return false;
        try {
            $result = $this->client->request($param, $method);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
        return $returnData ? $result : true;
    }

    private function setError($message)
    {
        $this->error = $message;
        //file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
    }
}
