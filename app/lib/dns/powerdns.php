<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

class powerdns implements DnsInterface
{
    private $url;
    private $apikey;
    private $server_id = 'localhost';
    private $error;
    private $domain;
    private $domainid;
    private $proxy;

    function __construct($config)
    {
        $this->url = 'http://' . $config['ak'] . ':' . $config['sk'] . '/api/v1';
        $this->apikey = $config['ext'];
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

    //获取域名列表
    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $data = $this->send_reuqest('GET', '/servers/' . $this->server_id . '/zones');
        if ($data) {
            $list = [];
            foreach ($data as $row) {
                $list[] = [
                    'DomainId' => $row['id'],
                    'Domain' => rtrim($row['name'], '.'),
                    'RecordCount' => 0,
                ];
            }
            return ['total' => count($list), 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $data = $this->send_reuqest('GET', '/servers/' . $this->server_id . '/zones/' . $this->domainid);
        if ($data) {
            $list = [];
            $rrset_id = 0;
            foreach ($data['rrsets'] as &$row) {
                $rrset_id++;
                $name = $row['name'] == $this->domainid ? '@' : str_replace('.' . $this->domainid, '', $row['name']);
                $row['host'] = $name;
                $row['id'] = $rrset_id;
                $record_id = 0;
                foreach ($row['records'] as &$record) {
                    $record_id++;
                    $record['id'] = $record_id;
                    $remark = !empty($row['comments']) ? $row['comments'][0]['content'] : null;
                    $value = $record['content'];
                    if ($row['type'] == 'MX') list($record['mx'], $value) = explode(' ', $record['content']);
                    $list[] = [
                        'RecordId' => $rrset_id . '_' . $record_id,
                        'Domain' => $this->domain,
                        'Name' => $name,
                        'Type' => $row['type'],
                        'Value' => $value,
                        'Line' => 'default',
                        'TTL' => $row['ttl'],
                        'MX' => isset($record['mx']) ? $record['mx'] : null,
                        'Status' => $record['disabled'] ? '0' : '1',
                        'Weight' => null,
                        'Remark' => $remark,
                        'UpdateTime' => null,
                    ];
                }
            }
            cache('powerdns_' . $this->domainid, $data['rrsets'], 86400);
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

    //获取子域名解析记录列表
    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    //获取解析记录详细信息
    public function getDomainRecordInfo($RecordId)
    {
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        if ($Type == 'TXT' && substr($Value, 0, 1) != '"') $Value = '"' . $Value . '"';
        if (($Type == 'CNAME' || $Type == 'MX') && substr($Value, -1) != '.') $Value .= '.';
        if ($Type == 'MX') $Value = intval($MX) . ' ' . $Value;
        $records = [];
        $rrsets = cache('powerdns_' . $this->domainid);
        if ($rrsets) {
            $rrsets_filter = array_filter($rrsets, function ($row) use ($Name, $Type) {
                return $row['host'] == $Name && $row['type'] == $Type;
            });
            if (!empty($rrsets_filter)) {
                $rrset = $rrsets_filter[array_key_first($rrsets_filter)];
                $records = $rrset['records'];
                $records_filter = array_filter($records, function ($record) use ($Value) {
                    return $record['content'] == $Value;
                });
                if (!empty($records_filter)) {
                    $this->setError('已存在相同记录');
                    return false;
                }
            }
        }
        $records[] = ['content' => $Value, 'disabled' => false];
        return $this->rrset_replace($Name, $Type, $TTL, $records, $Remark);
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        if ($Type == 'TXT' && substr($Value, 0, 1) != '"') $Value = '"' . $Value . '"';
        if (($Type == 'CNAME' || $Type == 'MX') && substr($Value, -1) != '.') $Value .= '.';
        if ($Type == 'MX') $Value = intval($MX) . ' ' . $Value;
        $rrsets = cache('powerdns_' . $this->domainid);
        $add = false;
        $res = false;
        if ($rrsets) {
            [$rrset_id, $record_id] = explode('_', $RecordId);
            $exist = false;
            foreach ($rrsets as &$rrset) {
                if ($rrset['id'] == $rrset_id) {
                    $records = $rrset['records'];
                    $records_filter = array_filter($records, function ($record) use ($Value, $record_id) {
                        return $record['content'] == $Value && $record['id'] != $record_id;
                    });
                    if (!empty($records_filter)) {
                        $this->setError('已存在相同记录');
                        return false;
                    }
                    foreach ($records as $i => &$record) {
                        if ($record['id'] == $record_id) {
                            $exist = true;
                            if ($rrset['host'] == $Name && $rrset['type'] == $Type) {
                                $record['content'] = $Value;
                            } else {
                                unset($records[$i]);
                                $add = true;
                            }
                            break;
                        }
                    }
                    if (!$exist) break;
                    $records = array_values($records);
                    if (!empty($records)) {
                        $res = $this->rrset_replace($rrset['host'], $rrset['type'], $TTL, $records, $Remark);
                    } else {
                        $res = $this->rrset_delete($rrset['host'], $rrset['type']);
                    }
                    $rrset['records'] = $records;
                    break;
                }
            }
            if (!$exist) {
                $this->setError('记录不存在，请刷新页面重试');
                return false;
            }
            cache('powerdns_' . $this->domainid, $rrsets, 86400);
            if ($res && $add) {
                $res = $this->addDomainRecord($Name, $Type, $Value, $Line, $TTL, $MX, $Weight, $Remark);
            }
            return $res;
        } else {
            $records[] = ['content' => $Value, 'disabled' => false];
            return $this->addDomainRecord($Name, $Type, $Value, $Line, $TTL, $MX, $Weight, $Remark);
        }
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $rrsets = cache('powerdns_' . $this->domainid);
        if (!$rrsets) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        [$rrset_id, $record_id] = explode('_', $RecordId);
        $exist = false;
        $res = false;
        foreach ($rrsets as &$rrset) {
            if ($rrset['id'] == $rrset_id) {
                $records = $rrset['records'];
                foreach ($records as $i => &$record) {
                    if ($record['id'] == $record_id) {
                        $exist = true;
                        unset($records[$i]);
                        break;
                    }
                }
                if (!$exist) break;
                $records = array_values($records);
                if (!empty($records)) {
                    $res = $this->rrset_replace($rrset['host'], $rrset['type'], $rrset['ttl'], $records);
                } else {
                    $res = $this->rrset_delete($rrset['host'], $rrset['type']);
                }
                $rrset['records'] = $records;
                break;
            }
        }
        if (!$exist) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        cache('powerdns_' . $this->domainid, $rrsets, 86400);
        return $res;
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $rrsets = cache('powerdns_' . $this->domainid);
        if (!$rrsets) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        [$rrset_id, $record_id] = explode('_', $RecordId);
        $exist = false;
        $res = false;
        foreach ($rrsets as &$rrset) {
            if ($rrset['id'] == $rrset_id) {
                $records = $rrset['records'];
                foreach ($records as &$record) {
                    if ($record['id'] == $record_id) {
                        $exist = true;
                        $record['disabled'] = $Status == '0';
                        break;
                    }
                }
                if (!$exist) break;
                $res = $this->rrset_replace($rrset['host'], $rrset['type'], $rrset['ttl'], $records);
                $rrset['records'] = $records;
                break;
            }
        }
        if (!$exist) {
            $this->setError('记录不存在，请刷新页面重试');
            return false;
        }
        cache('powerdns_' . $this->domainid, $rrsets, 86400);
        return $res;
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

    private function rrset_replace($host, $type, $ttl, $records, $remark = null)
    {
        $name = $host == '@' ? $this->domainid : $host . '.' . $this->domainid;
        $rrset = [
            'name' => $name,
            'type' => $type,
            'ttl' => intval($ttl),
            'changetype' => 'REPLACE',
            'records' => $records,
            'comments' => [],
        ];
        if (!empty($remark)) {
            $rrset['comments'] = [
                ['account' => '', 'content' => $remark]
            ];
        }
        $param = [
            'rrsets' => [
                $rrset
            ],
        ];
        return $this->send_reuqest('PATCH', '/servers/' . $this->server_id . '/zones/' . $this->domainid, $param);
    }

    private function rrset_delete($host, $type)
    {
        $name = $host == '@' ? $this->domainid : $host . '.' . $this->domainid;
        $param = [
            'rrsets' => [
                [
                    'name' => $name,
                    'type' => $type,
                    'changetype' => 'DELETE',
                ]
            ],
        ];
        return $this->send_reuqest('PATCH', '/servers/' . $this->server_id . '/zones/' . $this->domainid, $param);
    }

    private function send_reuqest($method, $path, $params = null)
    {
        $url = $this->url . $path;
        $headers['X-API-Key'] = $this->apikey;
        $body = null;
        if ($method == 'GET' || $method == 'DELETE') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            $body = json_encode($params);
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $response = curl_client($url, $body, null, null, $headers, $this->proxy, $method);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }

        $arr = json_decode($response['body'], true);
        if ($response['code'] < 400) {
            return is_array($arr) ? $arr : true;
        } elseif (isset($arr['error'])) {
            $this->setError($arr['error']);
            return false;
        } elseif (isset($arr['errors'])) {
            $this->setError(implode(',', $arr['errors']));
            return false;
        } else {
            $this->setError($response['body']);
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}
