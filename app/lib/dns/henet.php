<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;
use think\facade\Cache;

class henet implements DnsInterface
{
    private $username;
    private $password;
    private $baseUrl = 'https://dns.he.net/';
    private $error;
    private $domain;
    private $domainid;
    private $proxy;
    private $cookie = '';
    private $loggedIn = false;
    private $cacheKey;

    public function __construct($config)
    {
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->domain = $config['domain'];
        $this->domainid = $config['domainid'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->cacheKey = 'henet_cookie_' . md5($this->username . '|' . $this->password);
        $this->loadCachedSession();
    }

    public function getError()
    {
        return $this->error;
    }

    public function check()
    {
        return $this->getDomainList() !== false;
    }

    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $html = $this->login();
        if ($html === false) {
            return false;
        }

        $list = $this->parseDomains($html);
        if (!isNullOrEmpty($KeyWord)) {
            $list = array_values(array_filter($list, function ($row) use ($KeyWord) {
                return stripos($row['Domain'], $KeyWord) !== false;
            }));
        }

        $total = count($list);
        $offset = max(0, ($PageNumber - 1) * $PageSize);
        $list = array_slice($list, $offset, $PageSize);
        return ['total' => $total, 'list' => $list];
    }

    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $zoneid = $this->getZoneId();
        if ($zoneid === false) {
            return false;
        }

        $html = $this->request('GET', 'index.cgi?' . http_build_query([
            'hosted_dns_zoneid' => $zoneid,
            'menu' => 'edit_zone',
            'hosted_dns_editzone' => '',
        ]));
        if ($html === false) {
            return false;
        }

        $list = $this->parseRecords($html);
        if (!isNullOrEmpty($SubDomain)) {
            $list = array_values(array_filter($list, function ($row) use ($SubDomain) {
                return strcasecmp($row['Name'], $SubDomain) === 0;
            }));
        } else {
            if (!isNullOrEmpty($KeyWord)) {
                $list = array_values(array_filter($list, function ($row) use ($KeyWord) {
                    return stripos($row['Name'], $KeyWord) !== false || stripos($row['Value'], $KeyWord) !== false;
                }));
            }
            if (!isNullOrEmpty($Value)) {
                $list = array_values(array_filter($list, function ($row) use ($Value) {
                    return $row['Value'] == $Value;
                }));
            }
            if (!isNullOrEmpty($Type)) {
                $list = array_values(array_filter($list, function ($row) use ($Type) {
                    return strcasecmp($row['Type'], $Type) === 0;
                }));
            }
            if (!isNullOrEmpty($Status)) {
                $list = array_values(array_filter($list, function ($row) use ($Status) {
                    return $row['Status'] == $Status;
                }));
            }
        }

        $total = count($list);
        $offset = max(0, ($PageNumber - 1) * $PageSize);
        $list = array_slice($list, $offset, $PageSize);
        return ['total' => $total, 'list' => $list];
    }

    public function getSubDomainRecords($SubDomain, $PageNumber = 1, $PageSize = 20, $Type = null, $Line = null)
    {
        if ($SubDomain == '') $SubDomain = '@';
        return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
    }

    public function getDomainRecordInfo($RecordId)
    {
        $records = $this->getDomainRecords(1, 1000);
        if ($records === false) {
            return false;
        }
        foreach ($records['list'] as $row) {
            if ($row['RecordId'] == $RecordId) {
                return $row;
            }
        }
        $this->setError('解析记录不存在');
        return false;
    }

    public function addDomainRecord($Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $zoneid = $this->getZoneId();
        if ($zoneid === false) {
            return false;
        }

        $params = $this->buildRecordParams($zoneid, '', $Name, $Type, $Value, $TTL, $MX);
        $params['hosted_dns_editrecord'] = 'Submit';

        $html = $this->request('POST', 'index.cgi', $params);
        if ($html !== false && $this->isSuccess($html, 'Successfully added new record')) {
            $recordid = $this->findRecordInHtml($html, $Name, $Type, $Value);
            return $recordid ? $recordid : true;
        }

        $this->setError($this->extractMessage($html) ?: '添加解析记录失败');
        return false;
    }

    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $zoneid = $this->getZoneId();
        if ($zoneid === false) {
            return false;
        }

        $params = $this->buildRecordParams($zoneid, $RecordId, $Name, $Type, $Value, $TTL, $MX);
        $params['hosted_dns_editrecord'] = 'Update';

        $html = $this->request('POST', 'index.cgi', $params);
        if ($html !== false && $this->isSuccess($html, 'Successfully updated record')) {
            return true;
        }

        $this->setError($this->extractMessage($html) ?: '修改解析记录失败');
        return false;
    }

    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    public function deleteDomainRecord($RecordId)
    {
        $zoneid = $this->getZoneId();
        if ($zoneid === false) {
            return false;
        }

        $html = $this->request('POST', 'index.cgi', [
            'menu' => 'edit_zone',
            'hosted_dns_zoneid' => $zoneid,
            'hosted_dns_recordid' => $RecordId,
            'hosted_dns_editzone' => '1',
            'hosted_dns_delrecord' => '1',
            'hosted_dns_delconfirm' => 'delete',
        ]);
        if ($html !== false && $this->isSuccess($html, 'Successfully removed record')) {
            return true;
        }

        $this->setError($this->extractMessage($html) ?: '删除解析记录失败');
        return false;
    }

    public function setDomainRecordStatus($RecordId, $Status)
    {
        return false;
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
        return 300;
    }

    public function addDomain($Domain)
    {
        return false;
    }

    private function login()
    {
        if ($this->loggedIn) {
            return $this->request('GET', '');
        }

        $this->request('GET', '', null, false);
        $html = $this->request('POST', '', [
            'email' => $this->username,
            'pass' => $this->password,
        ], false);
        if ($html === false) {
            return false;
        }
        if (stripos($html, 'incorrect') !== false || stripos($html, 'Invalid') !== false) {
            $this->setError($this->extractMessage($html) ?: '登录失败，请检查用户名和密码');
            return false;
        }

        $domains = $this->parseDomains($html);
        if (empty($domains) && stripos($html, 'hosted_dns_zoneid') === false) {
            $this->setError($this->extractMessage($html) ?: '登录失败，未找到域名列表');
            return false;
        }

        $this->loggedIn = true;
        $this->saveCachedSession();
        return $html;
    }

    private function getZoneId()
    {
        if (!isNullOrEmpty($this->domainid)) {
            return $this->domainid;
        }
        if (isNullOrEmpty($this->domain)) {
            $this->setError('未指定域名');
            return false;
        }

        $domains = $this->getDomainList($this->domain, 1, 1000);
        if ($domains === false) {
            return false;
        }
        foreach ($domains['list'] as $row) {
            if (strcasecmp($row['Domain'], $this->domain) === 0) {
                $this->domainid = $row['DomainId'];
                return $this->domainid;
            }
        }

        $this->setError('域名不存在或无权限访问');
        return false;
    }

    private function buildRecordParams($zoneid, $recordid, $Name, $Type, $Value, $TTL, $MX)
    {
        return [
            'account' => '',
            'menu' => 'edit_zone',
            'Type' => strtoupper($Type),
            'hosted_dns_zoneid' => $zoneid,
            'hosted_dns_recordid' => $recordid,
            'hosted_dns_editzone' => '1',
            'Priority' => strtoupper($Type) == 'MX' ? intval($MX) : '',
            'Name' => $this->toFullName($Name),
            'Content' => $this->convertValue($Value, $Type),
            'TTL' => intval($TTL),
        ];
    }

    private function parseDomains($html)
    {
        $list = [];
        $patterns = [
            '/onclick="delete_dom\(this\);"[^>]*name="([^"]+)"[^>]*value="(\d+)"/i',
            '/name="([^"]+)"[^>]*value="(\d+)"[^>]*onclick="delete_dom\(this\);"/i',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $domain = html_entity_decode($match[1], ENT_QUOTES);
                $zoneid = $match[2];
                $list[$zoneid] = [
                    'DomainId' => $zoneid,
                    'Domain' => $domain,
                    'RecordCount' => 0,
                ];
            }
        }
        if (preg_match_all('/hosted_dns_zoneid=(\d+)[^"\']*["\'][^>]*>\s*([^<]+)\s*</i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $domain = trim(html_entity_decode($match[2], ENT_QUOTES));
                if ($domain !== '' && !isset($list[$match[1]])) {
                    $list[$match[1]] = [
                        'DomainId' => $match[1],
                        'Domain' => $domain,
                        'RecordCount' => 0,
                    ];
                }
            }
        }
        return array_values($list);
    }

    private function parseRecords($html)
    {
        $list = [];
        if (!preg_match_all('/<tr class="dns_tr" [^>]*>.*?<\/tr>/is', $html, $rows, PREG_SET_ORDER)) {
            return $list;
        }

        foreach ($rows as $row) {
            $cells = $this->extractCells($row[0]);
            if (count($cells) < 4) continue;
            $ttl = is_numeric($cells[4]) ? intval($cells[4]) : 0;
            $name = $cells[2];
            $type = strtoupper($cells[3]);
            $value = $cells[6];
            $priority = is_numeric($cells[5]) ? intval($cells[5]) : null;

            $list[] = [
                'RecordId' => $cells[1],
                'Domain' => $this->domain,
                'Name' => $this->fromFullName($name),
                'Type' => $type,
                'Value' => $this->normalizeValue($value, $type),
                'Line' => 'default',
                'TTL' => $ttl,
                'MX' => $priority,
                'Status' => stripos($row[0], 'disabled') !== false ? '0' : '1',
                'Weight' => null,
                'Remark' => null,
                'UpdateTime' => null,
            ];
        }

        return $list;
    }

    private function extractCells($html)
    {
        $cells = [];
        if (preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $html, $matches)) {
            foreach ($matches[1] as $cell) {
                $cell = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $cell);
                $cell = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $cell);
                $cell = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES));
                $cell = preg_replace('/\s+/', ' ', $cell);
                if ($cell !== '') {
                    $cells[] = $cell;
                }
            }
        }
        return $cells;
    }

    private function findRecordInHtml($html, $Name, $Type, $Value)
    {
        $records = $this->parseRecords($html);
        $name = $this->fromFullName($this->toFullName($Name));
        $value = $this->normalizeValue($this->convertValue($Value, $Type), $Type);
        foreach ($records as $record) {
            if (
                strcasecmp($record['Name'], $name) === 0 &&
                strcasecmp($record['Type'], $Type) === 0 &&
                $record['Value'] == $value
            ) {
                return $record;
            }
        }
        return false;
    }

    private function toFullName($name)
    {
        if ($name == '@' || $name == '') {
            return $this->domain;
        }
        if (str_ends_with($name, '.' . $this->domain) || strcasecmp($name, $this->domain) === 0) {
            return $name;
        }
        return $name . '.' . $this->domain;
    }

    private function fromFullName($name)
    {
        $name = rtrim($name, '.');
        if (strcasecmp($name, $this->domain) === 0) {
            return '@';
        }
        if (str_ends_with(strtolower($name), '.' . strtolower($this->domain))) {
            return substr($name, 0, -(strlen($this->domain) + 1));
        }
        return $name;
    }

    private function convertValue($value, $type)
    {
        if (strtoupper($type) == 'TXT') {
            return trim($value, '"');
        }
        return $value;
    }

    private function normalizeValue($value, $type)
    {
        $value = trim($value);
        if (strtoupper($type) == 'TXT') {
            return trim($value, '"');
        }
        return $value;
    }

    private function isSuccess($html, $message)
    {
        return stripos($html, $message) !== false;
    }

    private function extractMessage($html)
    {
        if (!$html) {
            return null;
        }
        if (preg_match('/<(?:div|span)[^>]*class="[^"]*(?:error|warn|success|message)[^"]*"[^>]*>(.*?)<\/(?:div|span)>/is', $html, $match)) {
            return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES));
        }
        if (preg_match('/(Successfully [^<]+|Error:[^<]+|Invalid [^<]+)/i', $html, $match)) {
            return trim(html_entity_decode($match[1], ENT_QUOTES));
        }
        return null;
    }

    private function request($method, $path, $params = null, $requireLogin = true, $allowRelogin = true)
    {
        if ($requireLogin && !$this->loggedIn && $this->login() === false) {
            return false;
        }

        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;
        try {
            $response = http_request($url, $params ? http_build_query($params) : null, $this->baseUrl, $this->cookie, null, $this->proxy, $method, 20);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }

        $this->storeCookie($response['headers']);
        if ($response['code'] >= 400) {
            $this->setError('HTTP请求失败：' . $response['code']);
            return false;
        }
        if ($requireLogin && $allowRelogin && $this->isLoginExpired($response['body'])) {
            $this->clearCachedSession();
            if ($this->login() === false) {
                return false;
            }
            return $this->request($method, $path, $params, true, false);
        }
        return $response['body'];
    }

    private function storeCookie($headers)
    {
        $cookies = [];
        if ($this->cookie !== '') {
            foreach (explode('; ', $this->cookie) as $cookie) {
                $parts = explode('=', $cookie, 2);
                if (count($parts) == 2) {
                    $cookies[$parts[0]] = $parts[1];
                }
            }
        }

        foreach ($headers as $name => $values) {
            if (strtolower($name) !== 'set-cookie') {
                continue;
            }
            foreach ((array)$values as $value) {
                $cookie = explode(';', $value, 2)[0];
                $parts = explode('=', $cookie, 2);
                if (count($parts) == 2) {
                    $cookies[$parts[0]] = $parts[1];
                }
            }
        }

        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $this->cookie = implode('; ', $pairs);
        if ($this->loggedIn && $this->cookie !== '') {
            $this->saveCachedSession();
        }
    }

    private function loadCachedSession()
    {
        $cookie = Cache::get($this->cacheKey);
        if (is_string($cookie) && $cookie !== '') {
            $this->cookie = $cookie;
            $this->loggedIn = true;
        }
    }

    private function saveCachedSession()
    {
        if ($this->cookie !== '') {
            Cache::set($this->cacheKey, $this->cookie, 3600);
        }
    }

    private function clearCachedSession()
    {
        $this->cookie = '';
        $this->loggedIn = false;
        Cache::delete($this->cacheKey);
    }

    private function isLoginExpired($html)
    {
        if (!$html) {
            return false;
        }
        return stripos($html, 'name="email"') !== false
            && stripos($html, 'name="pass"') !== false
            && stripos($html, 'hosted_dns_zoneid') === false;
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}
