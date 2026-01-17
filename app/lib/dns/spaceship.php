<?php

namespace app\lib\dns;

use app\lib\DnsInterface;

/**
 * @see https://docs.spaceship.dev/
 */
class spaceship implements DnsInterface
{
    private $apiKey;
    private $apiSecret;
    private $baseUrl = 'https://spaceship.dev/api/v1';
    private $error;
    private $domain;
    private $proxy;

    public function __construct($config)
    {
        $this->apiKey = $config['apikey'];
        $this->apiSecret = $config['apisecret'];
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
    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 100)
    {
        $param = ['take' => $PageSize, 'skip' => ($PageNumber - 1) * $PageSize];
        $data = $this->send_reuqest('GET', '/domains', $param);
        if ($data) {
            $list = [];
            foreach ($data['items'] as $row) {
                $list[] = [
                    'DomainId' => $row['name'],
                    'Domain' => $row['name'],
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
        $param = ['take' => $PageSize, 'skip' => ($PageNumber - 1) * $PageSize];
        if (!isNullOrEmpty($SubDomain)) {
            $param['take'] = 100;
            $param['skip'] = 0;
        }
        $data = $this->send_reuqest('GET', '/dns/records/' . $this->domain, $param);
        if ($data) {
            $list = [];
            foreach ($data['items'] as $row) {
                $type = $row['type'];
                $name = $row['name'];
                $mx = 0;
                if ('MX' == $type) {
                    $address = $row['exchange'];
                    $mx = $row['preference'];
                } else if ('CNAME' == $type) {
                    $address = $row['cname'];
                } else if ('TXT' == $type) {
                    $address = $row['value'];
                } else if ('PTR' == $type) {
                    $address = $row['pointer'];
                } else if ('NS' == $type) {
                    $address = $row['nameserver'];
                } else if ('CAA' == $type) {
                    $address = $row['flag'] . ' ' . $row['tag'] . ' ' . $row['value'];
                } else if ('SRV' == $type) {
                    $address = $row['priority'] . ' ' . $row['weight'] . ' ' . $row['port'] . ' ' . $row['target'];
                } else if ('ALIAS' == $type) {
                    $address = $row['aliasName'];
                } else {
                    $address = $row['address'];
                }

                $list[] = [
                    'RecordId' => $row['type'] . '|' . $name . '|' . $address . '|' . $mx,
                    'Domain' => $this->domain,
                    'Name' => $row['name'],
                    'Type' => $row['type'],
                    'Value' => $address,
                    'TTL' => $row['ttl'],
                    'Line' => 'default',
                    'MX' => $mx,
                    'Status' => '1',
                    'Weight' => null,
                    'Remark' => null,
                    'UpdateTime' => null,
                ];
            }
            if(!isNullOrEmpty($SubDomain)){
                $list = array_values(array_filter($list, function($v) use ($SubDomain){
                    return strcasecmp($v['Name'], $SubDomain) === 0;
                }));
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

    private function convertRecordItem($Name, $Type, $Value, $MX)
    {
        $item = [
            'type' => $Type,
            'name' => $Name,
        ];
        if ($Type == 'MX') {
            $item['exchange'] = $Value;
            $item['preference'] = (int)$MX;
        } else if ($Type == 'TXT') {
            $item['value'] = $Value;
        } else if ($Type == 'CNAME') {
            $item['cname'] = $Value;
        } else if ($Type == 'ALIAS') {
            $item['aliasName'] = $Value;
        } else if ($Type == 'NS') {
            $item['nameserver'] = $Value;
        } else if ($Type == 'PTR') {
            $item['pointer'] = $Value;
        } else if ($Type == 'CAA') {
            $parts = explode(' ', $Value, 3);
            if (count($parts) >= 3) {
                $item['flag'] = (int)$parts[0];
                $item['tag'] = $parts[1];
                $item['value'] = trim($parts[2], '"');
            }
        } else if ($Type == 'SRV') {
            $parts = explode(' ', $Value, 4);
            if (count($parts) >= 4) {
                $item['priority'] = (int)$parts[0];
                $item['weight'] = (int)$parts[1];
                $item['port'] = (int)$parts[2];
                $item['target'] = $parts[3];
            }
        } else {
            $item['address'] = $Value;
        }
        return $item;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $item = $this->convertRecordItem($Name, $Type, $Value, $MX);
        $item['ttl'] = (int)$TTL;
        $param = [
            'force' => false,
            'items' => [
                $item
            ]
        ];
        $data = $this->send_reuqest('PUT', '/dns/records/' . $this->domain, $param);
        return !isset($data);
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $item = $this->convertRecordItem($Name, $Type, $Value, $MX);
        $item['ttl'] = (int)$TTL;
        $param = [
            'force' => true,
            'items' => [
                $item
            ]
        ];
        $data = $this->send_reuqest('PUT', '/dns/records/' . $this->domain, $param);
        return !isset($data);
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $array = explode("|", $RecordId);
        $type = $array[0];
        $name = $array[1];
        $address = $array[2];
        $mx = $array[3];
        $item = $this->convertRecordItem($name, $type, $address, $mx);
        $param = [$item];
        $data = $this->send_reuqest('DELETE', '/dns/records/' . $this->domain, $param);
        return !isset($data);
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

    public function getDomainInfo()
    {
        return false;
    }

    public function getMinTTL()
    {
        return false;
    }

    public function addDomain($Domain)
    {
        return false;
    }

    private function send_reuqest($method, $path, $params = null)
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'X-API-Key' => $this->apiKey,
            'X-API-Secret' => $this->apiSecret,
        ];
        $body = '';
        if ($method == 'GET') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            $body = json_encode($params);
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
        } catch (\Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
        $arr = json_decode($response['body'], true);
        if ($response['code'] == 200 || $response['code'] == 204) {
            return $arr;
        } elseif (isset($arr['detail'])) {
            $this->setError($arr['detail']);
            return false;
        } else {
            $this->setError('http code: ' . $response['code']);
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}
