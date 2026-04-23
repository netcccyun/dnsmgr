<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

class dnsmgr implements DnsInterface
{
    private $uid;
    private $key;
    private $baseUrl;
    private $error;
    private $domain;
    private $domainid;
    private $proxy;
    private $domainInfo;

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
        return $data !== false;
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
        return $data !== false;
    }

    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        $param = [
            'recordid' => $RecordId,
            'remark' => $Remark,
        ];

        $data = $this->send_request('/api/record/remark/' . $this->domainid, $param);
        return $data !== false;
    }

    public function deleteDomainRecord($RecordId)
    {
        $param = [
            'recordid' => $RecordId,
        ];

        $data = $this->send_request('/api/record/delete/' . $this->domainid, $param);
        return $data !== false;
    }

    public function setDomainRecordStatus($RecordId, $Status)
    {
        $param = [
            'recordid' => $RecordId,
            'status' => $Status,
        ];

        $data = $this->send_request('/api/record/status/' . $this->domainid, $param);
        return $data !== false;
    }

    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    public function getRecordLine()
    {
        $data = $this->getDomainInfo();
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
        $data = $this->getDomainInfo();
        if ($data && isset($data['minTTL'])) {
            return $data['minTTL'];
        }
        return false;
    }

    public function getDomainInfo()
    {
        if (!empty($this->domainInfo)) return $this->domainInfo;
        $data = $this->send_request('/api/domain/' . $this->domainid, ['loginurl' => 0]);
        if ($data) {
            $this->domainInfo = $data;
            return $data;
        }
        return false;
    }

    public function addDomain($Domain)
    {
        return false;
    }

    private function send_request($path, $param = [])
    {
        try {
            $timestamp = (string)time();
            $signStr = $this->uid . $timestamp . $this->key;
            $sign = md5($signStr);

            $url = $this->baseUrl . $path;

            $param['uid'] = $this->uid;
            $param['timestamp'] = $timestamp;
            $param['sign'] = $sign;
            $postData = http_build_query($param);

            $response = http_request($url, $postData, null, null, null, $this->proxy);

            $result = json_decode($response['body'], true);
            if (isset($result['code']) && $result['code'] == 0) {
                return isset($result['data']) ? $result['data'] : null;
            } elseif (isset($result['rows']) && isset($result['total'])) {
                return $result;
            } elseif (isset($result['msg'])) {
                $this->setError($result['msg']);
                return false;
            } else {
                $this->setError($response['body']);
                return false;
            }
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
