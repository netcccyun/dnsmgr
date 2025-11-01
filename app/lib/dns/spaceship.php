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
        $this->apiKey = $config['ak'];
        $this->apiSecret = $config['sk'];
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

    private function send_reuqest($method, $path, $params = null)
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'X-API-Secret: ' . $this->apiSecret,
        ];

        $body = '';
        if ($method == 'GET') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            $body = json_encode($params);
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        if ($this->proxy) {
            curl_set_proxy($ch);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method == 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $errno = curl_errno($ch);

        if ($errno) {
            $this->setError('Curl error: ' . curl_error($ch));
        }

        curl_close($ch);
        if ($errno) return false;

        $arr = json_decode($response, true);
        if (!isset($arr['detail'])) {
            return $arr;
        } else {
            $this->setError($response['detail']);
            return false;
        }
    }

    //获取子域名解析记录列表

    private function setError($message)
    {
        $this->error = $message;
        //file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
    }

    //获取解析记录详细信息

    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        if ($SubDomain == '') $SubDomain = '@';
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    //添加解析记录

    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $param = ['take' => $PageSize, 'skip' => ($PageNumber - 1) * $PageSize];
        if (!isNullOrEmpty(($SubDomain))) {
            $param['host'] = $SubDomain;
        }
        $data = $this->send_reuqest('GET', '/dns/records/' . $this->domain, $param);
        if ($data) {
            $list = [];
            foreach ($data['items'] as $row) {
                $type = $row['type'];
                $name = $row['name'];
                if ('MX' == $type) {
                    $address = $row['exchange'];
                    $mx = $row['preference'];
                } else if ('CNAME' == $type) {
                    $address = $row['cname'];
                    $mx = 0;
                } else if ('TXT' == $type) {
                    $address = $row['value'];
                    $mx = 0;
                } else if ('PTR' == $type) {
                    $address = $row['pointer'];
                    $mx = 0;
                } else if ('NS' == $type) {
                    $address = $row['nameserver'];
                    $mx = 0;
                } else if ('HTTPS' == $type) {
                    $address = $row['targetName'] . $row['svcParams'] . '|' . $row['svcPriority'];
                    $mx = 0;
                } else if ('CAA' == $type) {
                    $address = $row['value'];
                    $mx = 0;
                } else if ('TLSA' == $type) {
                    $address = $row['associationData'];
                    $mx = 0;
                } else if ('SVRB' == $type) {
                    $address = $row['targetName'] . $row['svcParams'] . '|' . $row['svcPriority'];
                    $mx = 0;
                } else if ('ALIAS' == $type) {
                    $address = $row['aliasName'];
                    $mx = 0;
                } else {
                    $address = $row['address'];
                    $mx = 0;
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
            return ['total' => $data['total'], 'list' => $list];
        }
        return false;
    }

    //修改解析记录

    public function getDomainRecordInfo($RecordId)
    {
        return false;
    }

    //修改解析记录备注

    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = [
            'force' => true,
            'items' => [
                [
                    'type' => $this->convertType($Type),
                    'name' => $Name,
                    'address' => $Value,
                    'ttl' => $TTL,
                ]
            ]
        ];
        $data = $this->send_reuqest('PUT', '/dns/records/' . $this->domain, $param);
        return !isset($data);
    }

    //删除解析记录

    private function convertType($type)
    {
        return $type;
    }

    //设置解析记录状态

    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = [
            'force' => true,
            'items' => [
                [
                    'type' => $this->convertType($Type),
                    'name' => $Name,
                    'address' => $Value,
                    'ttl' => $TTL,
                ]
            ]
        ];
        $data = $this->send_reuqest('PUT', '/dns/records/' . $this->domain, $param);
        return !isset($data);
    }

    //获取解析记录操作日志

    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //获取解析线路列表

    public function deleteDomainRecord($RecordId)
    {
        $array = explode("|", $RecordId);
        $type = $array[0];
        $name = $array[1];
        $address = $array[2];
        $mx = $array[3];
        if ('MX' == $type) {
            $param = [
                [
                    'type' => $type,
                    'name' => $name,
                    'exchange' => $address,
                    'preference' => (int)$mx,
                ]
            ];
        } else if ('TXT' == $type) {
            $param = [
                [
                    'type' => $type,
                    'name' => $name,
                    'value' => $address,
                ]
            ];
        } else if ('CNAME' == $type) {
            $param = [
                [
                    'type' => $type,
                    'name' => $name,
                    'cname' => $address,
                ]
            ];
        } else if ('ALIAS' == $type) {
            $param = [
                [
                    'type' => $type,
                    'name' => $name,
                    'aliasName' => $address,
                ]
            ];
        } else {
            $param = [
                [
                    'type' => $type,
                    'name' => $name,
                    'address' => $address,
                ]
            ];
        }
        $data = $this->send_reuqest('DELETE', '/dns/records/' . $this->domain, $param);
        return !isset($data);
    }

    //获取域名信息

    public function setDomainRecordStatus($RecordId, $Status)
    {
        return false;
    }

    //获取域名最低TTL

    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

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
}