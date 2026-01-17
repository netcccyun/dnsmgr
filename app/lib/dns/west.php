<?php

namespace app\lib\dns;

use app\lib\DnsInterface;

/**
 * @see http://apipost.west.cn/
 */
class west implements DnsInterface
{
    private $username;
    private $api_password;
    private $baseUrl = 'https://api.west.cn/api/v2';
    private $error;
    private $domain;
    private $domainid;
    private $proxy;

    public function __construct($config)
    {
        $this->username = $config['username'];
        $this->api_password = $config['api_password'];
        $this->domain = $config['domain'];
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
        $param = ['act' => 'getdomains', 'page' => $PageNumber, 'limit' => $PageSize, 'domain' => $KeyWord];
        $data = $this->execute('/domain/', $param);
        if ($data) {
            $list = [];
            foreach ($data['items'] as $row) {
                $list[] = [
                    'DomainId' => $row['domain'],
                    'Domain' => $row['domain'],
                    'RecordCount' => 0,
                ];
            }
            return ['total' => $data['total'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $param = ['act' => 'getdnsrecord', 'domain' => $this->domain, 'type' => $Type, 'line' => $Line, 'host' => $KeyWord, 'value' => $Value, 'pageno' => $PageNumber, 'limit' => $PageSize];
        if (!isNullOrEmpty(($SubDomain))) {
            $param['host'] = $SubDomain;
        }
        $data = $this->execute('/domain/', $param);
        if ($data) {
            $list = [];
            foreach ($data['items'] as $row) {
                $list[] = [
                    'RecordId' => $row['id'],
                    'Domain' => $this->domain,
                    'Name' => $row['item'],
                    'Type' => $row['type'],
                    'Value' => $row['value'],
                    'Line' => $row['line'],
                    'TTL' => $row['ttl'],
                    'MX' => $row['level'],
                    'Status' => $row['pause'] == 1 ? '0' : '1',
                    'Weight' => null,
                    'Remark' => null,
                    'UpdateTime' => null,
                ];
            }
            return ['total' => $data['total'], 'list' => $list];
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
        $param = ['act' => 'adddnsrecord', 'domain' => $this->domain, 'host' => $Name, 'type' => $this->convertType($Type), 'value' => $Value, 'level' => $MX, 'ttl' => intval($TTL), 'line' => $Line];
        $data = $this->execute('/domain/', $param);
        return is_array($data) ? $data['id'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = ['act' => 'moddnsrecord', 'domain' => $this->domain, 'id' => $RecordId, 'type' => $this->convertType($Type), 'value' => $Value, 'level' => $MX, 'ttl' => intval($TTL), 'line' => $Line];
        $data = $this->execute('/domain/', $param);
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
        $param = ['act' => 'deldnsrecord', 'domain' => $this->domain, 'id' => $RecordId];
        $data = $this->execute('/domain/', $param);
        return is_array($data);
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $param = ['act' => 'pause', 'domain' => $this->domain, 'id' => $RecordId, 'val' => $Status == '1' ? '0' : '1'];
        $data = $this->execute('/domain/', $param);
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
        return [
            '' => ['name' => '默认', 'parent' => null],
            'LTEL' => ['name' => '电信', 'parent' => null],
            'LCNC' => ['name' => '联通', 'parent' => null],
            'LMOB' => ['name' => '移动', 'parent' => null],
            'LEDU' => ['name' => '教育网', 'parent' => null],
            'LSEO' => ['name' => '搜索引擎', 'parent' => null],
            'LFOR' => ['name' => '境外', 'parent' => null],
        ];
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

    public function addDomain($Domain)
    {
        return false;
    }

    private function convertType($type)
    {
        return $type;
    }

    private function execute($path, $params)
    {
        $params['username'] = $this->username;
        $params['time'] = getMillisecond();
        $params['token'] = md5($this->username.$this->api_password.$params['time']);
        try{
            $response = http_request($this->baseUrl . $path, http_build_query($params), null, null, null, $this->proxy);
        }catch(\Exception $e){
            $this->setError($e->getMessage());
            return false;
        }
        $response = mb_convert_encoding($response['body'], 'UTF-8', 'GBK');
        $arr = json_decode($response, true);
        if ($arr) {
            if ($arr['result'] == 200) {
                return isset($arr['data']) ? $arr['data'] : [];
            } else {
                $this->setError($arr['msg']);
                return false;
            }
        } else {
            $this->setError('返回数据解析失败');
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
        //file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
    }
}
