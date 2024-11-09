<?php

namespace app\lib\dns;

use app\lib\DnsInterface;

class huawei implements DnsInterface
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint = "dns.myhuaweicloud.com";
    private $error;
    private $domain;
    private $domainid;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['ak'];
        $this->SecretAccessKey = $config['sk'];
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

    //获取域名列表
    public function getDomainList($KeyWord = null, $PageNumber = 1, $PageSize = 20)
    {
        $offset = ($PageNumber - 1) * $PageSize;
        $query = ['offset' => $offset, 'limit' => $PageSize, 'name' => $KeyWord];
        $data = $this->send_reuqest('GET', '/v2/zones', $query);
        if ($data) {
            $list = [];
            foreach ($data['zones'] as $row) {
                $list[] = [
                    'DomainId' => $row['id'],
                    'Domain' => rtrim($row['name'], '.'),
                    'RecordCount' => $row['record_num'],
                ];
            }
            return ['total' => $data['metadata']['total_count'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $offset = ($PageNumber - 1) * $PageSize;
        $query = ['type' => $Type, 'line_id' => $Line, 'name' => $KeyWord, 'offset' => $offset, 'limit' => $PageSize];
        if (!isNullOrEmpty($Status)) {
            $Status = $Status == '1' ? 'ACTIVE' : 'DISABLE';
            $query['status'] = $Status;
        }
        if (!isNullOrEmpty($SubDomain)) {
            $SubDomain = $this->getHost($SubDomain);
            $query['name'] = $SubDomain;
            $query['search_mode'] = 'equal';
        }
        $data = $this->send_reuqest('GET', '/v2.1/zones/'.$this->domainid.'/recordsets', $query);
        if ($data) {
            $list = [];
            foreach ($data['recordsets'] as $row) {
                if ($row['name'] == $row['zone_name']) {
                    $row['name'] = '@';
                }
                if ($row['type'] == 'MX') {
                    list($row['mx'], $row['records']) = explode(' ', $row['records'][0]);
                }
                $list[] = [
                    'RecordId' => $row['id'],
                    'Domain' => rtrim($row['zone_name'], '.'),
                    'Name' => str_replace('.'.$row['zone_name'], '', $row['name']),
                    'Type' => $row['type'],
                    'Value' => $row['records'],
                    'Line' => $row['line'],
                    'TTL' => $row['ttl'],
                    'MX' => isset($row['mx']) ? $row['mx'] : null,
                    'Status' => $row['status'] == 'ACTIVE' ? '1' : '0',
                    'Weight' => $row['weight'],
                    'Remark' => $row['description'],
                    'UpdateTime' => $row['updated_at'],
                ];
            }
            return ['total' => $data['metadata']['total_count'], 'list' => $list];
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
        $data = $this->send_reuqest('GET', '/v2.1/zones/'.$this->domainid.'/recordsets/'.$RecordId);
        if ($data) {
            if ($data['name'] == $data['zone_name']) {
                $data['name'] = '@';
            }
            if ($data['type'] == 'MX') {
                list($data['mx'], $data['records']) = explode(' ', $data['records'][0]);
            }
            return [
                'RecordId' => $data['id'],
                'Domain' => rtrim($data['zone_name'], '.'),
                'Name' => str_replace('.'.$data['zone_name'], '', $data['name']),
                'Type' => $data['type'],
                'Value' => $data['records'],
                'Line' => $data['line'],
                'TTL' => $data['ttl'],
                'MX' => isset($data['mx']) ? $data['mx'] : null,
                'Status' => $data['status'] == 'ACTIVE' ? '1' : '0',
                'Weight' => $data['weight'],
                'Remark' => $data['description'],
                'UpdateTime' => $data['updated_at'],
            ];
        }
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $Name = $this->getHost($Name);
        if ($Type == 'TXT' && substr($Value, 0, 1) != '"') {
            $Value = '"'.$Value.'"';
        }
        $records = explode(',', $Value);
        $params = ['name' => $Name, 'type' => $this->convertType($Type), 'records' => $records, 'line' => $Line, 'ttl' => intval($TTL), 'description' => $Remark];
        if ($Type == 'MX') {
            $params['records'][0] = intval($MX) . ' ' . $Value;
        }
        if ($Weight > 0) {
            $params['weight'] = intval($Weight);
        }
        $data = $this->send_reuqest('POST', '/v2.1/zones/'.$this->domainid.'/recordsets', null, $params);
        return is_array($data) ? $data['id'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $Name = $this->getHost($Name);
        if ($Type == 'TXT' && substr($Value, 0, 1) != '"') {
            $Value = '"'.$Value.'"';
        }
        $records = explode(',', $Value);
        $params = ['name' => $Name, 'type' => $this->convertType($Type), 'records' => $records, 'line' => $Line, 'ttl' => intval($TTL), 'description' => $Remark];
        if ($Type == 'MX') {
            $params['records'][0] = intval($MX) . ' ' . $Value;
        }
        if ($Weight > 0) {
            $params['weight'] = intval($Weight);
        }
        $data = $this->send_reuqest('PUT', '/v2.1/zones/'.$this->domainid.'/recordsets/'.$RecordId, null, $params);
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
        $data = $this->send_reuqest('DELETE', '/v2.1/zones/'.$this->domainid.'/recordsets/'.$RecordId);
        return is_array($data);
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $Status = $Status == '1' ? 'ENABLE' : 'DISABLE';
        $params = ['status' => $Status];
        $data = $this->send_reuqest('PUT', '/v2.1/recordsets/'.$RecordId.'/statuses/set', null, $params);
        return is_array($data);
    }

    //获取解析记录操作日志
    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        return false;
    }

    //获取解析线路列表
    public function getRecordLine()
    {
        $file_path = app()->getBasePath().'data'.DIRECTORY_SEPARATOR.'huawei_line.json';
        $content = file_get_contents($file_path);
        $data = json_decode($content, true);
        if ($data) {
            return $data;
            $list = [$data['DEFAULT']['id'] => ['name' => $data['DEFAULT']['zh'], 'parent' => null]];
            $this->processLineList($list, $data['ISP'], null, 1, 1);
            $this->processLineList($list, $data['REGION'], null, null, 1);
            //file_put_contents($file_path, json_encode($list, JSON_UNESCAPED_UNICODE));
            return $list;
        }
        return false;
    }

    private function processLineList(&$list, $line_list, $parent, $rootId = null, $rootName = null)
    {
        foreach ($line_list as $row) {
            if ($rootId && $rootId !== 1) {
                $row['id'] = $rootId.'_'.$row['id'];
            }
            if ($rootName && $rootName !== 1) {
                $row['zh'] = $rootName.'_'.$row['zh'];
            }
            $list[$row['id']] = ['name' => $row['zh'], 'parent' => $parent];
            if (isset($row['children']) && !empty($row['children'])) {
                $this->processLineList($list, $row['children'], $row['id'], $rootId === 1 ? $row['id'] : $rootId, $rootName === 1 ? $row['zh'] : $rootName);
            }
        }
    }

    //获取域名概览信息
    public function getDomainInfo()
    {
        return $this->send_reuqest('GET', '/v2/zones/'.$this->domainid);
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return false;
    }

    private function convertType($type)
    {
        return $type;
    }

    private function getHost($Name)
    {
        if ($Name == '@') {
            $Name = '';
        } else {
            $Name .= '.';
        }
        $Name .= $this->domain . '.';
        return $Name;
    }

    private function send_reuqest($method, $path, $query = null, $params = null)
    {
        if (!empty($query)) {
            $query = array_filter($query, function ($a) { return $a !== null;});
        }
        if (!empty($params)) {
            $params = array_filter($params, function ($a) { return $a !== null;});
        }

        $time = time();
        $date = gmdate("Ymd\THis\Z", $time);
        $body = !empty($params) ? json_encode($params) : '';
        $headers = [
            'Host' => $this->endpoint,
            'X-Sdk-Date' => $date,
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/json';
        }

        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $time);
        $headers['Authorization'] = $authorization;

        $url = 'https://'.$this->endpoint.$path;
        if (!empty($query)) {
            $url .= '?'.http_build_query($query);
        }
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key.': '.$value;
        }
        return $this->curl($method, $url, $body, $header);
    }

    private function generateSign($method, $path, $query, $headers, $body, $time)
    {
        $algorithm = "SDK-HMAC-SHA256";

        // step 1: build canonical request string
        $httpRequestMethod = $method;
        $canonicalUri = $path;
        if (substr($canonicalUri, -1) != "/") {
            $canonicalUri .= "/";
        }
        $canonicalQueryString = $this->getCanonicalQueryString($query);
        [$canonicalHeaders, $signedHeaders] = $this->getCanonicalHeaders($headers);
        $hashedRequestPayload = hash("sha256", $body);
        $canonicalRequest = $httpRequestMethod."\n"
            .$canonicalUri."\n"
            .$canonicalQueryString."\n"
            .$canonicalHeaders."\n"
            .$signedHeaders."\n"
            .$hashedRequestPayload;

        // step 2: build string to sign
        $date = gmdate("Ymd\THis\Z", $time);
        $hashedCanonicalRequest = hash("sha256", $canonicalRequest);
        $stringToSign = $algorithm."\n"
            .$date."\n"
            .$hashedCanonicalRequest;

        // step 3: sign string
        $signature = hash_hmac("sha256", $stringToSign, $this->SecretAccessKey);

        // step 4: build authorization
        $authorization = $algorithm . ' Access=' . $this->AccessKeyId . ", SignedHeaders=" . $signedHeaders . ", Signature=" . $signature;

        return $authorization;
    }

    private function escape($str)
    {
        $search = ['+', '*', '%7E'];
        $replace = ['%20', '%2A', '~'];
        return str_replace($search, $replace, urlencode($str));
    }

    private function getCanonicalQueryString($parameters)
    {
        if (empty($parameters)) {
            return '';
        }
        ksort($parameters);
        $canonicalQueryString = '';
        foreach ($parameters as $key => $value) {
            $canonicalQueryString .= '&' . $this->escape($key). '=' . $this->escape($value);
        }
        return substr($canonicalQueryString, 1);
    }

    private function getCanonicalHeaders($oldheaders)
    {
        $headers = array();
        foreach ($oldheaders as $key => $value) {
            $headers[strtolower($key)] = trim($value);
        }
        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($headers as $key => $value) {
            $canonicalHeaders .= $key . ':' . $value . "\n";
            $signedHeaders .= $key . ';';
        }
        $signedHeaders = substr($signedHeaders, 0, -1);
        return [$canonicalHeaders, $signedHeaders];
    }

    private function curl($method, $url, $body, $header)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno) {
            $this->setError('Curl error: ' . curl_error($ch));
        }
        curl_close($ch);
        if ($errno) {
            return false;
        }

        $arr = json_decode($response, true);
        if ($arr) {
            if (isset($arr['error_msg'])) {
                $this->setError($arr['error_msg']);
                return false;
            } elseif (isset($arr['message'])) {
                $this->setError($arr['message']);
                return false;
            } else {
                return $arr;
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
