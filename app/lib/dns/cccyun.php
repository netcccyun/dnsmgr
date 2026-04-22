<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

class cccyun implements DnsInterface
{
    private $uid;
    private $key;
    private $baseUrl;
    private $error;
    private $domain;
    private $domainid;
    private $proxy;

    public function __construct($config)
    {
        $this->uid = $config['uid'];
        $this->key = $config['key'];
        $this->baseUrl = rtrim($config['base_url'], '/');
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->proxy = $proxy;
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

    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $offset = ($PageNumber - 1) * $PageSize;
        $param = [
            'offset' => $offset,
            'limit' => $PageSize,
        ];
        if (!isNullOrEmpty($KeyWord)) {
            $param['kw'] = $KeyWord;
        }

        $data = $this->send_request('/api/domain', $param);
        if ($data && isset($data['rows'])) {
            $list = [];
            foreach ($data['rows'] as $row) {
                $list[] = [
                    'DomainId' => $row['id'],
                    'Domain' => $row['name'],
                    'RecordCount' => $row['recordcount'],
                ];
            }
            return ['total' => $data['total'], 'list' => $list];
        }
        return false;
    }

    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $offset = ($PageNumber - 1) * $PageSize;
        $param = [
            'offset' => $offset,
            'limit' => $PageSize,
        ];
        if (!isNullOrEmpty($KeyWord)) $param['keyword'] = $KeyWord;
        if (!isNullOrEmpty($SubDomain)) $param['subdomain'] = $SubDomain;
        if (!isNullOrEmpty($Value)) $param['value'] = $Value;
        if (!isNullOrEmpty($Type)) $param['type'] = $Type;
        if (!isNullOrEmpty($Line)) $param['line'] = $Line;
        if (!isNullOrEmpty($Status)) $param['status'] = $Status;

        $data = $this->send_request('/api/record/data/' . $this->domainid, $param);
        if ($data && isset($data['rows'])) {
            $list = [];
            foreach ($data['rows'] as $row) {
                $list[] = [
                    'RecordId' => $row['RecordId'],
                    'Domain' => $row['Domain'],
                    'Name' => $row['Name'],
                    'Type' => $row['Type'],
                    'Value' => $row['Value'],
                    'Line' => $row['Line'],
                    'LineName' => $row['LineName'],
                    'TTL' => $row['TTL'],
                    'MX' => $row['MX'],
                    'Status' => $row['Status'],
                    'Weight' => $row['Weight'],
                    'Remark' => $row['Remark'],
                    'UpdateTime' => $row['UpdateTime'],
                ];
            }
            return ['total' => $data['total'], 'list' => $list];
        } elseif ($this->error == '记录列表为空。') {
            return ['total' => 0, 'list' => []];
        }
        return false;
    }

    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        if ($SubDomain == '') $SubDomain = '@';
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    public function getDomainRecordInfo($RecordId)
    {
        $param = [
            'recordid' => $RecordId,
        ];
        $data = $this->send_request('/api/record/data/' . $this->domainid, $param);
        if ($data && isset($data['rows'][0])) {
            $row = $data['rows'][0];
            return [
                'RecordId' => $row['RecordId'],
                'Domain' => $row['Domain'],
                'Name' => $row['Name'],
                'Type' => $row['Type'],
                'Value' => $row['Value'],
                'Line' => $row['Line'],
                'TTL' => $row['TTL'],
                'MX' => $row['MX'],
                'Status' => $row['Status'],
                'Weight' => $row['Weight'],
                'Remark' => $row['Remark'],
                'UpdateTime' => $row['UpdateTime'],
            ];
        }
        return false;
    }

    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = [
            'name' => $Name,
            'type' => $Type,
            'value' => $Value,
            'line' => $Line,
            'ttl' => intval($TTL),
        ];
        if ($Type == 'MX' && !isNullOrEmpty($MX)) {
            $param['mx'] = intval($MX);
        }
        if (!isNullOrEmpty($Weight)) {
            $param['weight'] = intval($Weight);
        }
        if (!isNullOrEmpty($Remark)) {
            $param['remark'] = $Remark;
        }

        $data = $this->send_request('/api/record/add/' . $this->domainid, $param);
        return is_array($data) && isset($data['code']) && $data['code'] == 0;
    }

    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = [
            'recordid' => $RecordId,
            'name' => $Name,
            'type' => $Type,
            'value' => $Value,
            'line' => $Line,
            'ttl' => intval($TTL),
        ];
        if ($Type == 'MX' && !isNullOrEmpty($MX)) {
            $param['mx'] = intval($MX);
        }
        if (!isNullOrEmpty($Weight)) {
            $param['weight'] = intval($Weight);
        }
        if (!isNullOrEmpty($Remark)) {
            $param['remark'] = $Remark;
        }

        $data = $this->send_request('/api/record/update/' . $this->domainid, $param);
        return is_array($data) && isset($data['code']) && $data['code'] == 0;
    }

    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        $param = [
            'recordid' => $RecordId,
            'remark' => $Remark,
        ];

        $data = $this->send_request('/api/record/remark/' . $this->domainid, $param);
        return is_array($data) && isset($data['code']) && $data['code'] == 0;
    }

    public function deleteDomainRecord($RecordId)
    {
        $param = [
            'recordid' => $RecordId,
        ];

        $data = $this->send_request('/api/record/delete/' . $this->domainid, $param);
        return is_array($data) && isset($data['code']) && $data['code'] == 0;
    }

    public function setDomainRecordStatus($RecordId, $Status)
    {
        $param = [
            'recordid' => $RecordId,
            'status' => $Status,
        ];

        $data = $this->send_request('/api/record/status/' . $this->domainid, $param);
        return is_array($data) && isset($data['code']) && $data['code'] == 0;
    }

    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        $this->setError('该DNS服务商不支持查看日志');
        return false;
    }

    public function getRecordLine()
    {
        $data = $this->send_request('/api/domain/' . $this->domainid, ['loginurl' => 0]);
        if ($data && isset($data['recordLine'])) {
            $list = [];
            foreach ($data['recordLine'] as $row) {
                $list[$row['id']] = [
                    'name' => $row['name'],
                    'parent' => isset($row['parent']) ? $row['parent'] : null,
                ];
            }
            return $list;
        }
        return false;
    }

    public function getMinTTL()
    {
        $data = $this->send_request('/api/domain/' . $this->domainid, ['loginurl' => 0]);
        if ($data && isset($data['minTTL'])) {
            return $data['minTTL'];
        }
        return false;
    }

    public function addDomain($Domain)
    {
        $this->setError('该DNS服务商不支持添加域名');
        return false;
    }

    private function send_request($path, $param = [])
    {
        try {
            $timestamp = time();
            $signStr = $this->uid . $timestamp . $this->key;
            $sign = md5($signStr);

            $url = $this->baseUrl . $path;

            $param['uid'] = $this->uid;
            $param['timestamp'] = $timestamp;
            $param['sign'] = $sign;
            $postData = http_build_query($param);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded'
            ]);

            if ($this->proxy) {
                $proxy_config = Db::name('config')->where('key', 'proxy')->value('value');
                if ($proxy_config) {
                    $proxy_info = json_decode($proxy_config, true);
                    if ($proxy_info && $proxy_info['open'] == 1) {
                        curl_setopt($ch, CURLOPT_PROXY, $proxy_info['ip'] . ':' . $proxy_info['port']);
                        if (!empty($proxy_info['username']) && !empty($proxy_info['password'])) {
                            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_info['username'] . ':' . $proxy_info['password']);
                        }
                    }
                }
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new Exception('CURL Error: ' . $curlError);
            }

            if ($httpCode != 200) {
                throw new Exception('HTTP Error: ' . $httpCode);
            }

            $result = json_decode($response, true);
            if (!$result) {
                throw new Exception('JSON Decode Error: ' . $response);
            }

            if (isset($result['code']) && $result['code'] != 0 && isset($result['msg'])) {
                throw new Exception($result['msg']);
            }

            return isset($result['data']) ? $result['data'] : $result;

        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}