<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

class technitium implements DnsInterface
{
    private $url;
    private $token;
    private $error;
    private $domain;
    private $domainid;
    private $proxy;

    function __construct($config)
    {
        $this->url = rtrim($config['url'], '/') . '/api';
        $this->token = $config['token'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->domain = $config['domain'];
        $this->domainid = $config['domainid'];
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

    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $data = $this->send_request('GET', '/zones/list');
        if ($data && isset($data['response']['zones'])) {
            $list = [];
            foreach ($data['response']['zones'] as $zone) {
                $list[] = [
                    'DomainId' => $zone['name'],
                    'Domain' => $zone['name'],
                    'RecordCount' => 0,
                ];
            }
            if (!isNullOrEmpty($KeyWord)) {
                $list = array_values(array_filter($list, function ($v) use ($KeyWord) {
                    return strpos($v['Domain'], $KeyWord) !== false;
                }));
            }
            return ['total' => count($list), 'list' => $list];
        }
        return false;
    }

    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $params = ['domain' => $this->domain, 'listZone' => 'true'];
        $data = $this->send_request('GET', '/zones/records/get', $params);
        if ($data && isset($data['response']['records'])) {
            $list = [];
            $records = $data['response']['records'];
            foreach ($records as $i => &$row) {
                $row['id'] = $i;
                $name = $row['name'] == $this->domain ? '@' : str_replace('.' . $this->domain, '', $row['name']);
                $value = '';
                $mx = null;
                $rData = $row['rData'];
                
                if ($row['type'] == 'A' || $row['type'] == 'AAAA') {
                    $value = isset($rData['ipAddress']) ? $rData['ipAddress'] : '';
                } elseif ($row['type'] == 'CNAME') {
                    $value = isset($rData['cname']) ? $rData['cname'] : '';
                } elseif ($row['type'] == 'NS') {
                    $value = isset($rData['nameServer']) ? $rData['nameServer'] : '';
                } elseif ($row['type'] == 'MX') {
                    $value = isset($rData['exchange']) ? $rData['exchange'] : '';
                    $mx = isset($rData['preference']) ? $rData['preference'] : 1;
                } elseif ($row['type'] == 'TXT') {
                    $value = isset($rData['text']) ? $rData['text'] : '';
                } elseif ($row['type'] == 'SRV') {
                    $value = (isset($rData['priority']) ? $rData['priority'] : 0) . ' ' . (isset($rData['weight']) ? $rData['weight'] : 0) . ' ' . (isset($rData['port']) ? $rData['port'] : 0) . ' ' . (isset($rData['target']) ? $rData['target'] : '');
                } elseif ($row['type'] == 'PTR') {
                    $value = isset($rData['ptrName']) ? $rData['ptrName'] : '';
                } elseif ($row['type'] == 'CAA') {
                    $value = (isset($rData['flags']) ? $rData['flags'] : 0) . ' ' . (isset($rData['tag']) ? $rData['tag'] : '') . ' "' . (isset($rData['value']) ? $rData['value'] : '') . '"';
                } elseif ($row['type'] == 'ANAME') {
                    $value = isset($rData['aname']) ? $rData['aname'] : '';
                } elseif ($row['type'] == 'DNAME') {
                    $value = isset($rData['dname']) ? $rData['dname'] : '';
                } elseif ($row['type'] == 'APP') {
                    $value = (isset($rData['appName']) ? $rData['appName'] : '') . ' ' . (isset($rData['classPath']) ? $rData['classPath'] : '');
                    if (!empty($rData['recordData'])) {
                        $value .= ' ' . $rData['recordData'];
                    }
                }

                $list[] = [
                    'RecordId' => $i,
                    'Domain' => $this->domain,
                    'Name' => $name,
                    'Type' => $row['type'],
                    'Value' => $value,
                    'Line' => 'default',
                    'TTL' => $row['ttl'],
                    'MX' => $mx,
                    'Status' => $row['disabled'] ? '0' : '1',
                    'Weight' => null,
                    'Remark' => isset($row['comments']) ? $row['comments'] : null,
                    'UpdateTime' => null,
                ];
            }
            cache('technitium_' . $this->domain, $records, 86400);

            if (!isNullOrEmpty($SubDomain)) {
                $list = array_values(array_filter($list, function ($v) use ($SubDomain) {
                    return strcasecmp($v['Name'], $SubDomain) === 0;
                }));
            } else {
                if (!isNullOrEmpty($KeyWord)) {
                    $list = array_values(array_filter($list, function ($v) use ($KeyWord) {
                        return strpos($v['Name'], $KeyWord) !== false || strpos($v['Value'], $KeyWord) !== false;
                    }));
                }
                if (!isNullOrEmpty($Value)) {
                    $list = array_values(array_filter($list, function ($v) use ($Value) {
                        return $v['Value'] == $Value;
                    }));
                }
                if (!isNullOrEmpty($Type)) {
                    $list = array_values(array_filter($list, function ($v) use ($Type) {
                        return $v['Type'] == $Type;
                    }));
                }
                if (!isNullOrEmpty($Status)) {
                    $list = array_values(array_filter($list, function ($v) use ($Status) {
                        return $v['Status'] == $Status;
                    }));
                }
            }
            return ['total' => count($list), 'list' => $list];
        }
        return false;
    }

    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    public function getDomainRecordInfo($RecordId)
    {
        return false;
    }

    private function buildRecordParams($Type, $Value, $MX = 1)
    {
        $params = [];
        if ($Type == 'A' || $Type == 'AAAA') {
            $params['ipAddress'] = $Value;
        } elseif ($Type == 'CNAME') {
            $params['cname'] = $Value;
        } elseif ($Type == 'NS') {
            $params['nameServer'] = $Value;
        } elseif ($Type == 'MX') {
            $params['exchange'] = $Value;
            $params['preference'] = intval($MX);
        } elseif ($Type == 'TXT') {
            $params['text'] = $Value;
        } elseif ($Type == 'SRV') {
            $parts = explode(' ', $Value);
            if (count($parts) == 4) {
                $params['priority'] = $parts[0];
                $params['weight'] = $parts[1];
                $params['port'] = $parts[2];
                $params['target'] = $parts[3];
            }
        } elseif ($Type == 'PTR') {
            $params['ptrName'] = $Value;
        } elseif ($Type == 'CAA') {
            $parts = explode(' ', $Value, 3);
            if (count($parts) == 3) {
                $params['flags'] = $parts[0];
                $params['tag'] = $parts[1];
                $params['value'] = trim($parts[2], '"');
            }
        } elseif ($Type == 'ANAME') {
            $params['aname'] = $Value;
        } elseif ($Type == 'DNAME') {
            $params['dname'] = $Value;
        } elseif ($Type == 'APP') {
            $parts = explode(' ', $Value, 3);
            if (count($parts) >= 2) {
                $params['appName'] = $parts[0];
                $params['classPath'] = $parts[1];
                $params['recordData'] = rtrim(isset($parts[2]) ? $parts[2] : '');
            } else {
                $params['appName'] = rtrim($Value);
            }
        }
        return $params;
    }

    private function getOldValueParams($Type, $rData)
    {
        $params = [];
        if ($Type == 'A' || $Type == 'AAAA') {
            $params['ipAddress'] = isset($rData['ipAddress']) ? $rData['ipAddress'] : '';
        } elseif ($Type == 'CNAME') {
            $params['cname'] = isset($rData['cname']) ? $rData['cname'] : '';
        } elseif ($Type == 'NS') {
            $params['nameServer'] = isset($rData['nameServer']) ? $rData['nameServer'] : '';
        } elseif ($Type == 'MX') {
            $params['exchange'] = isset($rData['exchange']) ? $rData['exchange'] : '';
            $params['preference'] = isset($rData['preference']) ? $rData['preference'] : 1;
        } elseif ($Type == 'TXT') {
            $params['text'] = isset($rData['text']) ? $rData['text'] : '';
        } elseif ($Type == 'SRV') {
            $params['priority'] = isset($rData['priority']) ? $rData['priority'] : 0;
            $params['weight'] = isset($rData['weight']) ? $rData['weight'] : 0;
            $params['port'] = isset($rData['port']) ? $rData['port'] : 0;
            $params['target'] = isset($rData['target']) ? $rData['target'] : '';
        } elseif ($Type == 'PTR') {
            $params['ptrName'] = isset($rData['ptrName']) ? $rData['ptrName'] : '';
        } elseif ($Type == 'CAA') {
            $params['flags'] = isset($rData['flags']) ? $rData['flags'] : 0;
            $params['tag'] = isset($rData['tag']) ? $rData['tag'] : '';
            $params['value'] = isset($rData['value']) ? $rData['value'] : '';
        } elseif ($Type == 'ANAME') {
            $params['aname'] = isset($rData['aname']) ? $rData['aname'] : '';
        } elseif ($Type == 'DNAME') {
            $params['dname'] = isset($rData['dname']) ? $rData['dname'] : '';
        } elseif ($Type == 'APP') {
            $params['appName'] = isset($rData['appName']) ? $rData['appName'] : '';
            $params['classPath'] = isset($rData['classPath']) ? $rData['classPath'] : '';
            if (!empty($rData['recordData'])) {
                $params['recordData'] = $rData['recordData'];
            }
        }
        return $params;
    }

    private function getNewValueParams($Type, $Value, $MX = 1)
    {
        $params = [];
        if ($Type == 'A' || $Type == 'AAAA') {
            $params['newIpAddress'] = $Value;
        } elseif ($Type == 'CNAME') {
            $params['newCname'] = $Value;
        } elseif ($Type == 'NS') {
            $params['newNameServer'] = $Value;
        } elseif ($Type == 'MX') {
            $params['newExchange'] = $Value;
            $params['newPreference'] = intval($MX);
        } elseif ($Type == 'TXT') {
            $params['newText'] = $Value;
        } elseif ($Type == 'SRV') {
            $parts = explode(' ', $Value);
            if (count($parts) == 4) {
                $params['newPriority'] = $parts[0];
                $params['newWeight'] = $parts[1];
                $params['newPort'] = $parts[2];
                $params['newTarget'] = $parts[3];
            }
        } elseif ($Type == 'PTR') {
            $params['newPtrName'] = $Value;
        } elseif ($Type == 'CAA') {
            $parts = explode(' ', $Value, 3);
            if (count($parts) == 3) {
                $params['newFlags'] = $parts[0];
                $params['newTag'] = $parts[1];
                $params['newValue'] = trim($parts[2], '"');
            }
        } elseif ($Type == 'ANAME') {
            $params['newAName'] = $Value;
        } elseif ($Type == 'DNAME') {
            $params['newDName'] = $Value;
        } elseif ($Type == 'APP') {
            $parts = explode(' ', $Value, 3);
            if (count($parts) >= 2) {
                $params['appName'] = $parts[0];
                $params['classPath'] = $parts[1];
                $params['recordData'] = rtrim(isset($parts[2]) ? $parts[2] : '');
            } else {
                $params['appName'] = rtrim($Value);
            }
        }
        return $params;
    }

    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $domain = $Name == '@' ? $this->domain : $Name . '.' . $this->domain;
        $params = [
            'domain' => $domain,
            'zone' => $this->domain,
            'type' => $Type,
            'ttl' => intval($TTL)
        ];
        if (!isNullOrEmpty($Remark)) {
            $params['comments'] = $Remark;
        }
        $valParams = $this->buildRecordParams($Type, $Value, $MX);
        if (empty($valParams) && $Type != 'SOA') {
            $this->setError('不受支持的记录类型或参数解析失败');
            return false;
        }
        $params = array_merge($params, $valParams);

        $result = $this->send_request('POST', '/zones/records/add', $params);
        return $result !== false;
    }

    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $records = cache('technitium_' . $this->domain);
        if (!$records || !isset($records[$RecordId])) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        
        $oldRecord = $records[$RecordId];
        $domain = $oldRecord['name'];
        $newDomain = $Name == '@' ? $this->domain : $Name . '.' . $this->domain;

        if ($oldRecord['type'] == 'APP') {
            $oldValue = (isset($oldRecord['rData']['appName']) ? $oldRecord['rData']['appName'] : '') . ' ' . (isset($oldRecord['rData']['classPath']) ? $oldRecord['rData']['classPath'] : '');
            if (!empty($oldRecord['rData']['recordData'])) {
                $oldValue .= ' ' . $oldRecord['rData']['recordData'];
            }
            if ($oldValue != rtrim($Value) || $domain != $newDomain) {
                $this->deleteDomainRecord($RecordId);
                return $this->addDomainRecord($Name, $Type, $Value, $Line, $TTL, $MX, $Weight, $Remark);
            }
        }
        
        $params = [
            'domain' => $domain,
            'zone' => $this->domain,
            'type' => $oldRecord['type'],
            'ttl' => intval($TTL),
        ];

        if ($domain != $newDomain) {
            $params['newDomain'] = $newDomain;
        }
        
        $params['comments'] = empty($Remark) ? "" : $Remark;

        $oldValParams = $this->getOldValueParams($oldRecord['type'], $oldRecord['rData']);
        $newValParams = $this->getNewValueParams($Type, $Value, $MX);
        
        $params = array_merge($params, $oldValParams, $newValParams);
        $result = $this->send_request('POST', '/zones/records/update', $params);
        return $result !== false;
    }

    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        $records = cache('technitium_' . $this->domain);
        if (!$records || !isset($records[$RecordId])) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        
        $oldRecord = $records[$RecordId];
        $domain = $oldRecord['name'];
        
        $params = [
            'domain' => $domain,
            'zone' => $this->domain,
            'type' => $oldRecord['type'],
            'comments' => $Remark,
        ];
        $oldValParams = $this->getOldValueParams($oldRecord['type'], $oldRecord['rData']);
        $params = array_merge($params, $oldValParams);
        
        $result = $this->send_request('POST', '/zones/records/update', $params);
        return $result !== false;
    }

    public function deleteDomainRecord($RecordId)
    {
        $records = cache('technitium_' . $this->domain);
        if (!$records || !isset($records[$RecordId])) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        
        $oldRecord = $records[$RecordId];
        $domain = $oldRecord['name'];
        
        $params = [
            'domain' => $domain,
            'zone' => $this->domain,
            'type' => $oldRecord['type'],
        ];
        
        $oldValParams = $this->getOldValueParams($oldRecord['type'], $oldRecord['rData']);
        $params = array_merge($params, $oldValParams);
        
        $result = $this->send_request('POST', '/zones/records/delete', $params);
        return $result !== false;
    }

    public function setDomainRecordStatus($RecordId, $Status)
    {
        $records = cache('technitium_' . $this->domain);
        if (!$records || !isset($records[$RecordId])) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        
        $oldRecord = $records[$RecordId];
        $domain = $oldRecord['name'];
        
        $params = [
            'domain' => $domain,
            'zone' => $this->domain,
            'type' => $oldRecord['type'],
            'disable' => $Status == '0' ? 'true' : 'false',
        ];
        
        $oldValParams = $this->getOldValueParams($oldRecord['type'], $oldRecord['rData']);
        $params = array_merge($params, $oldValParams);
        
        $result = $this->send_request('POST', '/zones/records/update', $params);
        return $result !== false;
    }

    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    public function getRecordLine()
    {
        return ['default' => ['name' => '默认', 'parent' => null]];
    }

    public function getMinTTL()
    {
        return false;
    }

    public function addDomain($Domain)
    {
        $params = [
            'zone' => $Domain,
            'type' => 'Primary'
        ];
        $result = $this->send_request('POST', '/zones/create', $params);
        if ($result && isset($result['response']['domain'])) {
            return ['id' => $result['response']['domain'], 'name' => $result['response']['domain']];
        }
        return false;
    }

    private function send_request($method, $path, $params = [])
    {
        $url = $this->url . $path;
        $params['token'] = $this->token;
        
        $body = null;
        if ($method == 'GET' || $method == 'DELETE') {
            $url .= '?' . http_build_query($params);
        } else {
            $body = http_build_query($params);
        }
        
        try {
            $response = http_request($url, $body, null, null, null, $this->proxy, $method);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }

        $arr = json_decode($response['body'], true);
        if (isset($arr['status']) && $arr['status'] == 'ok') {
            return $arr;
        } elseif (isset($arr['errorMessage'])) {
            $this->setError($arr['errorMessage']);
            return false;
        } else {
            $this->setError('API 请求失败');
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}
