<?php

namespace app\lib\dns;

use app\lib\DnsInterface;

class dnsla implements DnsInterface
{
    private $apiid;
    private $apisecret;
    private $baseUrl = 'https://api.dns.la';
    private $typeList = [1 => 'A', 2 => 'NS', 5 => 'CNAME', 15 => 'MX', 16 => 'TXT', 28 => 'AAAA', 33 => 'SRV', 257 => 'CAA', 256 => 'URL转发'];
    private $error;
    private $domain;
    private $domainid;
    private $proxy;

    public function __construct($config)
    {
        $this->apiid = $config['apiid'];
        $this->apisecret = $config['apisecret'];
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
        $param = ['pageIndex' => $PageNumber, 'pageSize' => $PageSize];
        $data = $this->execute('GET', '/api/domainList', $param);
        if ($data) {
            $list = [];
            foreach ($data['results'] as $row) {
                $list[] = [
                    'DomainId' => $row['id'],
                    'Domain' => rtrim($row['displayDomain'], '.'),
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
        $param = ['domainId' => $this->domainid, 'pageIndex' => $PageNumber, 'pageSize' => $PageSize];
        if (!isNullOrEmpty($KeyWord)) {
            $param['host'] = $KeyWord;
        }
        if (!isNullOrEmpty($Type)) {
            $param['type'] = $this->convertType($Type);
        }
        if (!isNullOrEmpty($Line)) {
            $param['lineId'] = $Line;
        }
        if (!isNullOrEmpty($SubDomain)) {
            $param['host'] = $SubDomain;
        }
        if (!isNullOrEmpty($Value)) {
            $param['data'] = $Value;
        }
        $data = $this->execute('GET', '/api/recordList', $param);
        if ($data) {
            $list = [];
            foreach ($data['results'] as $row) {
                $list[] = [
                    'RecordId' => $row['id'],
                    'Domain' => $this->domain,
                    'Name' => $row['host'],
                    'Type' => $this->convertTypeId($row['type'], isset($row['domaint']) ? $row['domaint'] : false),
                    'Value' => $row['data'],
                    'Line' => $row['lineId'],
                    'TTL' => $row['ttl'],
                    'MX' => isset($row['preference']) ? $row['preference'] : null,
                    'Status' => $row['disable'] ? '0' : '1',
                    'Weight' => isset($row['weight']) ? $row['weight'] : null,
                    'Remark' => null,
                    'UpdateTime' => date('Y-m-d H:i:s', $row['updatedAt']),
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
        $param = ['domainId' => $this->domainid, 'type' => $this->convertType($Type), 'host' => $Name, 'data' => $Value, 'ttl' => intval($TTL), 'lineId' => $Line];
        if ($Type == 'MX') $param['preference'] = intval($MX);
        if ($Type == 'REDIRECT_URL') {
            $param['type'] = 256;
            $param['dominant'] = true;
        } elseif ($Type == 'FORWARD_URL') {
            $param['type'] = 256;
            $param['dominant'] = false;
        }
        if ($Weight > 0) $param['weight'] = $Weight;
        $data = $this->execute('POST', '/api/record', $param);
        return is_array($data) ? $data['id'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = ['id' => $RecordId, 'type' => $this->convertType($Type), 'host' => $Name, 'data' => $Value, 'ttl' => intval($TTL), 'lineId' => $Line];
        if ($Type == 'MX') $param['preference'] = intval($MX);
        if ($Type == 'REDIRECT_URL') {
            $param['type'] = 256;
            $param['dominant'] = true;
        } elseif ($Type == 'FORWARD_URL') {
            $param['type'] = 256;
            $param['dominant'] = false;
        }
        if ($Weight > 0) $param['weight'] = $Weight;
        $data = $this->execute('PUT', '/api/record', $param);
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
        $param = ['id' => $RecordId];
        $data = $this->execute('DELETE', '/api/record', $param);
        return $data !== false;
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $param = ['id' => $RecordId, 'disable' => $Status == '0' ? true : false];
        $data = $this->execute('PUT', '/api/recordDisable', $param);
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
        $param = ['domain' => $this->domain];
        $data = $this->execute('GET', '/api/availableLine', $param);
        if ($data) {
            array_multisort(array_column($data, 'order'), SORT_ASC, $data);
            $list = [];
            foreach ($data as $row) {
                if ($row['id'] == '0') $row['id'] = '';
                $list[$row['id']] = ['name' => $row['value'], 'parent' => !empty($row['pid']) ? $row['pid'] : null];
            }
            return $list;
        }
        return false;
    }

    //获取域名信息
    public function getDomainInfo()
    {
        $param = ['id' => $this->domainid];
        $data = $this->execute('GET', '/api/domain', $param);
        return $data;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        $param = ['id' => $this->domainid];
        $data = $this->execute('GET', '/api/dnsMeasures', $param);
        if ($data && isset($data['minTTL'])) {
            return $data['minTTL'];
        }
        return false;
    }

    public function addDomain($Domain)
    {
        $param = ['domain' => $Domain];
        $data = $this->execute('POST', '/api/domain', $param);
        if ($data) {
            return ['id' => $data['id'], 'name' => $Domain];
        }
        return false;
    }

    private function convertType($type)
    {
        $typeList = array_flip($this->typeList);
        return $typeList[$type];
    }

    private function convertTypeId($typeId, $domaint)
    {
        if ($typeId == 256) return $domaint ? 'REDIRECT_URL' : 'FORWARD_URL';
        return $this->typeList[$typeId];
    }

    private function execute($method, $path, $params = null)
    {
        $token = base64_encode($this->apiid.':'.$this->apisecret);
        $header = [
            'Authorization' => 'Basic '.$token,
            'Content-Type' => 'application/json; charset=utf-8'
        ];
        if ($method == 'POST' || $method == 'PUT') {
            $response = $this->curl($method, $path, $header, json_encode($params));
        } else {
            if ($params) {
                $path .= '?'.http_build_query($params);
            }
            $response = $this->curl($method, $path, $header);
        }
        if (!$response) {
            return false;
        }
        $arr = json_decode($response, true);
        if ($arr) {
            if ($arr['code'] == 200) {
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
        if ($response['code'] == 200) {
            return $response['body'];
        } elseif ($response['code'] == 401) {
            $this->setError('认证失败');
            return false;
        } else {
            $this->setError('http code: '.$response['code']);
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
        //file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
    }
}
