<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use app\lib\client\AWS as AWSClient;
use Exception;

class aws implements DnsInterface
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $error;
    private $domain;
    private $domainid;
    private AWSClient $client;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->SecretAccessKey = $config['SecretAccessKey'];
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new AWSClient($this->AccessKeyId, $this->SecretAccessKey, 'route53.amazonaws.com', 'route53', '2013-04-01', 'us-east-1', $proxy);
        $this->domain = $config['domain'] ?? '';
        $this->domainid = $config['domainid'] ?? '';
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
        $zones = $this->listAllHostedZones();
        if ($zones === false) {
            return false;
        }
        $list = [];
        foreach ($zones as $row) {
            $name = rtrim($row['Name'] ?? '', '.');
            if ($KeyWord && stripos($name, $KeyWord) === false) {
                continue;
            }
            $list[] = [
                'DomainId' => $this->normalizeZoneId($row['Id'] ?? ''),
                'Domain' => $name,
                'RecordCount' => intval($row['ResourceRecordSetCount'] ?? 0),
            ];
        }
        $total = count($list);
        $list = array_slice($list, ($PageNumber - 1) * $PageSize, $PageSize);
        return ['total' => $total, 'list' => $list];
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $sets = $this->listAllRecordSets();
        if ($sets === false) {
            return false;
        }
        $list = [];
        foreach ($sets as $row) {
            if (isset($row['AliasTarget']) || !empty($row['SetIdentifier'])) {
                continue;
            }
            $type = $row['Type'] ?? '';
            if ($type === 'SOA') {
                continue;
            }
            $name = $this->fromFqdn($row['Name'] ?? '');
            $ttl = intval($row['TTL'] ?? 300);
            $records = $this->xmlList($row['ResourceRecords'] ?? [], 'ResourceRecord');
            foreach ($records as $record) {
                $raw = $record['Value'] ?? '';
                $parsed = $this->parseValue($type, $raw);
                $displayValue = is_array($parsed) ? $parsed['value'] : $parsed;
                $list[] = [
                    'RecordId' => $this->encodeRecordId($name, $type, $raw),
                    'Domain' => $this->domain,
                    'Name' => $name,
                    'Type' => $type,
                    'Value' => $displayValue,
                    'Line' => 'default',
                    'TTL' => $ttl,
                    'MX' => is_array($parsed) ? $parsed['mx'] : null,
                    'Status' => '1',
                    'Weight' => null,
                    'Remark' => null,
                    'UpdateTime' => null,
                ];
            }
        }
        if (!isNullOrEmpty($SubDomain)) {
            $list = array_values(array_filter($list, function ($v) use ($SubDomain) {
                return strcasecmp($v['Name'], $SubDomain) === 0;
            }));
        } else {
            if (!isNullOrEmpty($KeyWord)) {
                $list = array_values(array_filter($list, function ($v) use ($KeyWord) {
                    return stripos($v['Name'], $KeyWord) !== false || stripos((string)$v['Value'], $KeyWord) !== false;
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
        }
        return ['total' => count($list), 'list' => $list];
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
        $old = $this->decodeRecordId($RecordId);
        if (!$old) {
            return false;
        }
        $parsed = $this->parseValue($old['Type'], $old['Value']);
        return [
            'RecordId' => $RecordId,
            'Domain' => $this->domain,
            'Name' => $old['Name'],
            'Type' => $old['Type'],
            'Value' => is_array($parsed) ? $parsed['value'] : $parsed,
            'Line' => 'default',
            'TTL' => 300,
            'MX' => is_array($parsed) ? $parsed['mx'] : null,
            'Status' => '1',
            'Weight' => null,
            'Remark' => null,
            'UpdateTime' => null,
        ];
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $type = $this->convertType($Type);
        $fqdn = $this->toFqdn($Name);
        $raw = $this->formatValue($type, $Value, $MX);
        $set = $this->getRecordSet($fqdn, $type);
        if ($set === false) {
            return false;
        }
        $values = $set ? $this->extractValues($set) : [];
        foreach ($values as $item) {
            if ($item === $raw) {
                $this->setError('已存在相同记录');
                return false;
            }
        }
        $values[] = $raw;
        $ttl = $set ? intval($set['TTL']) : intval($TTL);
        if ($TTL) $ttl = intval($TTL);
        if ($this->changeRecordSets([[
            'Action' => 'UPSERT',
            'Name' => $fqdn,
            'Type' => $type,
            'TTL' => $ttl,
            'Values' => $values,
        ]])) {
            return $this->encodeRecordId($this->fromFqdn($fqdn), $type, $raw);
        }
        return false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $old = $this->decodeRecordId($RecordId);
        if (!$old) {
            $this->setError('记录ID无效');
            return false;
        }
        $type = $this->convertType($Type);
        $fqdn = $this->toFqdn($Name);
        $newRaw = $this->formatValue($type, $Value, $MX);
        $oldFqdn = $this->toFqdn($old['Name']);
        $oldType = $old['Type'];
        $oldRaw = $old['Value'];

        if (strcasecmp($oldFqdn, $fqdn) === 0 && strtoupper($oldType) === strtoupper($type)) {
            $set = $this->getRecordSet($fqdn, $type);
            if ($set === false) {
                return false;
            }
            $values = $set ? $this->extractValues($set) : [];
            $replaced = false;
            foreach ($values as $i => $item) {
                if ($item === $oldRaw) {
                    $values[$i] = $newRaw;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $values[] = $newRaw;
            }
            $values = array_values(array_unique($values));
            return $this->changeRecordSets([[
                'Action' => 'UPSERT',
                'Name' => $fqdn,
                'Type' => $type,
                'TTL' => intval($TTL),
                'Values' => $values,
            ]]);
        }

        if (!$this->deleteDomainRecord($RecordId)) {
            return false;
        }
        return $this->addDomainRecord($Name, $Type, $Value, $Line, $TTL, $MX, $Weight, $Remark);
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $old = $this->decodeRecordId($RecordId);
        if (!$old) {
            $this->setError('记录ID无效');
            return false;
        }
        $fqdn = $this->toFqdn($old['Name']);
        $type = $old['Type'];
        $set = $this->getRecordSet($fqdn, $type);
        if ($set === false) {
            return false;
        }
        if (!$set) {
            $this->setError('记录不存在');
            return false;
        }
        $values = $this->extractValues($set);
        $remaining = array_values(array_filter($values, function ($item) use ($old) {
            return $item !== $old['Value'];
        }));
        if (count($remaining) === count($values)) {
            $this->setError('记录不存在');
            return false;
        }
        if (empty($remaining)) {
            return $this->changeRecordSets([[
                'Action' => 'DELETE',
                'Name' => $fqdn,
                'Type' => $type,
                'TTL' => intval($set['TTL']),
                'Values' => $values,
            ]]);
        }
        return $this->changeRecordSets([[
            'Action' => 'UPSERT',
            'Name' => $fqdn,
            'Type' => $type,
            'TTL' => intval($set['TTL']),
            'Values' => $remaining,
        ]]);
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

    //获取域名信息
    public function getDomainInfo()
    {
        $res = $this->getDomainList($this->domain);
        if ($res && !empty($res['list'])) {
            return $res['list'][0];
        }
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return 60;
    }

    public function addDomain($Domain)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<CreateHostedZoneRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/">'
            . '<Name>' . htmlspecialchars($Domain, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Name>'
            . '<CallerReference>' . htmlspecialchars('dnsmgr-' . $Domain . '-' . uniqid('', true), ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</CallerReference>'
            . '</CreateHostedZoneRequest>';
        $data = $this->send_request('POST', '/hostedzone', $xml);
        if ($data && isset($data['HostedZone']['Id'])) {
            return [
                'id' => $this->normalizeZoneId($data['HostedZone']['Id']),
                'name' => rtrim($data['HostedZone']['Name'], '.'),
            ];
        }
        return false;
    }

    private function listAllHostedZones()
    {
        $list = [];
        $marker = null;
        do {
            $params = ['maxitems' => '100'];
            if ($marker) {
                $params['marker'] = $marker;
            }
            $data = $this->send_request('GET', '/hostedzone', $params);
            if ($data === false) {
                return false;
            }
            $zones = $this->xmlList($data['HostedZones'] ?? [], 'HostedZone');
            foreach ($zones as $zone) {
                $list[] = $zone;
            }
            $truncated = ($data['IsTruncated'] ?? 'false') === 'true';
            $marker = $truncated ? ($data['NextMarker'] ?? null) : null;
        } while ($truncated && $marker);
        return $list;
    }

    private function listAllRecordSets()
    {
        $list = [];
        $params = ['maxitems' => '300'];
        do {
            $data = $this->send_request('GET', '/hostedzone/' . $this->zoneId() . '/rrset', $params);
            if ($data === false) {
                return false;
            }
            $sets = $this->xmlList($data['ResourceRecordSets'] ?? [], 'ResourceRecordSet');
            foreach ($sets as $set) {
                $list[] = $set;
            }
            $truncated = ($data['IsTruncated'] ?? 'false') === 'true';
            $params = ['maxitems' => '300'];
            if ($truncated) {
                if (!empty($data['NextRecordName'])) $params['name'] = $data['NextRecordName'];
                if (!empty($data['NextRecordType'])) $params['type'] = $data['NextRecordType'];
                if (!empty($data['NextRecordIdentifier'])) $params['identifier'] = $data['NextRecordIdentifier'];
            }
        } while ($truncated);
        return $list;
    }

    private function getRecordSet($fqdn, $type)
    {
        $data = $this->send_request('GET', '/hostedzone/' . $this->zoneId() . '/rrset', [
            'name' => $fqdn,
            'type' => $type,
            'maxitems' => '1',
        ]);
        if ($data === false) {
            return false;
        }
        $sets = $this->xmlList($data['ResourceRecordSets'] ?? [], 'ResourceRecordSet');
        if (empty($sets)) {
            return null;
        }
        $set = $sets[0];
        if (strcasecmp(rtrim($set['Name'] ?? '', '.'), rtrim($fqdn, '.')) !== 0 || strtoupper($set['Type'] ?? '') !== strtoupper($type)) {
            return null;
        }
        if (isset($set['AliasTarget']) || !empty($set['SetIdentifier'])) {
            return null;
        }
        return $set;
    }

    private function changeRecordSets($changes)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/">'
            . '<ChangeBatch><Changes>';
        foreach ($changes as $change) {
            $xml .= '<Change><Action>' . htmlspecialchars($change['Action'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Action>';
            $xml .= '<ResourceRecordSet>';
            $xml .= '<Name>' . htmlspecialchars($change['Name'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Name>';
            $xml .= '<Type>' . htmlspecialchars($change['Type'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Type>';
            $xml .= '<TTL>' . intval($change['TTL']) . '</TTL>';
            $xml .= '<ResourceRecords>';
            foreach ($change['Values'] as $value) {
                $xml .= '<ResourceRecord><Value>' . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</Value></ResourceRecord>';
            }
            $xml .= '</ResourceRecords></ResourceRecordSet></Change>';
        }
        $xml .= '</Changes></ChangeBatch></ChangeResourceRecordSetsRequest>';
        $data = $this->send_request('POST', '/hostedzone/' . $this->zoneId() . '/rrset', $xml);
        return $data !== false;
    }

    private function extractValues($set)
    {
        $values = [];
        foreach ($this->xmlList($set['ResourceRecords'] ?? [], 'ResourceRecord') as $record) {
            if (isset($record['Value'])) {
                $values[] = $record['Value'];
            }
        }
        return $values;
    }

    private function convertType($type)
    {
        if ($type === 'SPF') {
            return 'TXT';
        }
        return $type;
    }

    private function formatValue($type, $value, $mx = 1)
    {
        if ($type == 'TXT') {
            if ($value === '' || substr($value, 0, 1) != '"') {
                $value = '"' . $value . '"';
            }
            return $value;
        }
        if ($type == 'MX') {
            return intval($mx) . ' ' . rtrim($value, '.') . '.';
        }
        if (in_array($type, ['CNAME', 'NS', 'PTR'])) {
            return rtrim($value, '.') . '.';
        }
        if ($type == 'SRV') {
            $parts = preg_split('/\s+/', trim($value));
            if (count($parts) >= 4) {
                $parts[3] = rtrim($parts[3], '.') . '.';
                return implode(' ', $parts);
            }
        }
        return $value;
    }

    private function parseValue($type, $raw)
    {
        if ($type == 'TXT') {
            if (preg_match('/^"(.*)"$/s', $raw, $m) && strpos($m[1], '" "') === false) {
                return str_replace('\\"', '"', $m[1]);
            }
            return $raw;
        }
        if ($type == 'MX') {
            $parts = explode(' ', $raw, 2);
            return [
                'mx' => intval($parts[0]),
                'value' => isset($parts[1]) ? rtrim($parts[1], '.') : '',
            ];
        }
        if (in_array($type, ['CNAME', 'NS', 'PTR'])) {
            return rtrim($raw, '.');
        }
        if ($type == 'SRV') {
            $parts = preg_split('/\s+/', trim($raw));
            if (count($parts) >= 4) {
                $parts[3] = rtrim($parts[3], '.');
                return implode(' ', $parts);
            }
        }
        return $raw;
    }

    private function toFqdn($name)
    {
        $domain = $this->domainAscii();
        if ($name == '@' || $name == '') {
            return $domain . '.';
        }
        if (substr($name, -1) == '.') {
            return $name;
        }
        return $name . '.' . $domain . '.';
    }

    private function fromFqdn($fqdn)
    {
        $fqdn = rtrim($fqdn, '.');
        $domain = $this->domainAscii();
        if (strcasecmp($fqdn, $domain) === 0 || strcasecmp($fqdn, rtrim($this->domain, '.')) === 0) {
            return '@';
        }
        $suffix = '.' . $domain;
        if (strlen($fqdn) > strlen($suffix) && strcasecmp(substr($fqdn, -strlen($suffix)), $suffix) === 0) {
            return substr($fqdn, 0, -strlen($suffix));
        }
        $suffix = '.' . rtrim($this->domain, '.');
        if (strlen($fqdn) > strlen($suffix) && strcasecmp(substr($fqdn, -strlen($suffix)), $suffix) === 0) {
            return substr($fqdn, 0, -strlen($suffix));
        }
        return $fqdn;
    }

    private function domainAscii()
    {
        $domain = rtrim($this->domain, '.');
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii) return $ascii;
        }
        return $domain;
    }

    private function encodeRecordId($name, $type, $value)
    {
        return rtrim(strtr(base64_encode($name . "\x1e" . $type . "\x1e" . $value), '+/', '-_'), '=');
    }

    private function decodeRecordId($recordId)
    {
        $decoded = base64_decode(strtr($recordId, '-_', '+/'), true);
        if ($decoded === false) {
            return false;
        }
        $parts = explode("\x1e", $decoded, 3);
        if (count($parts) !== 3) {
            return false;
        }
        return ['Name' => $parts[0], 'Type' => $parts[1], 'Value' => $parts[2]];
    }

    private function zoneId()
    {
        return $this->normalizeZoneId($this->domainid);
    }

    private function normalizeZoneId($id)
    {
        return str_replace('/hostedzone/', '', $id);
    }

    private function xmlList($parent, $key)
    {
        if (!is_array($parent) || !isset($parent[$key])) {
            return [];
        }
        $items = $parent[$key];
        if ($items === '' || $items === null) {
            return [];
        }
        if (!is_array($items)) {
            return [];
        }
        if (array_key_exists(0, $items)) {
            return $items;
        }
        return [$items];
    }

    private function send_request($method, $path, $params = [])
    {
        try {
            return $this->client->requestXmlN($method, $path, $params);
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
