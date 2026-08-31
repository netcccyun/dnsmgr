<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Exception;

class dynv6 implements DnsInterface
{
    private $token;
    private $baseUrl = 'https://dynv6.com/api/v2';
    private $error;
    private $domain;
    private $zoneID; // 缓存zone的数字ID
    private $proxy;

    function __construct($config)
    {
        $this->token = $config['token'];
        $this->domain = $config['domain'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    // 获取zone的数字ID（带缓存）
    private function getZoneID()
    {
        if ($this->zoneID !== null) {
            return $this->zoneID;
        }

        // 通过域名查询zone信息
        $data = $this->send_request('GET', '/zones/by-name/' . $this->domain);
        if ($data && isset($data['id'])) {
            $this->zoneID = $data['id'];
            return $this->zoneID;
        }

        $this->setError('无法获取域名的Zone ID，请确认域名已添加到dynv6');
        return false;
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
        $data = $this->send_request('GET', '/zones');
        if ($data !== false) {
            $list = [];
            // API 返回直接数组，不是 {zones: [...]}
            if (is_array($data)) {
                foreach ($data as $row) {
                    $zoneName = isset($row['name']) ? $row['name'] : '';
                    $zoneId = isset($row['id']) ? $row['id'] : 0;
                    if (!empty($zoneName)) {
                        $list[] = [
                            'DomainId' => $zoneId,  // 使用数字ID
                            'Domain' => $zoneName,
                            'RecordCount' => 0,
                        ];
                    }
                }
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

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $zoneID = $this->getZoneID();
        if ($zoneID === false) {
            return false;
        }
        
        $data = $this->send_request('GET', '/zones/' . $zoneID . '/records');
        if ($data !== false) {
            $list = [];
            // API 返回格式不一致：
            // - 0条记录: []
            // - 1条记录: {...} 单个对象
            // - 2+条记录: [{...}, {...}]
            // 需要统一处理为数组
            if (is_array($data)) {
                // 判断是单个对象还是数组
                if (isset($data['id']) && isset($data['type'])) {
                    // 单个对象，包装成数组
                    $data = [$data];
                }
                
                foreach ($data as $row) {
                    $recordId = isset($row['id']) ? $row['id'] : null;
                    $name = isset($row['name']) ? $row['name'] : '';
                    $type = isset($row['type']) ? strtoupper($row['type']) : '';
                    $value = isset($row['data']) ? $row['data'] : '';
                    
                    // 处理名称：dynv6返回的是FQDN，需要转为相对名称
                    // 例如：example.com -> @，www.example.com -> www
                    if (empty($name) || $name === $this->domain) {
                        $name = '@';
                    } elseif (substr($name, -strlen($this->domain) - 1) === '.' . $this->domain) {
                        // 去掉域名后缀，保留子域名部分
                        $name = substr($name, 0, -strlen($this->domain) - 1);
                    }
                    
                    $list[] = [
                        'RecordId' => $recordId,
                        'Domain' => $this->domain,
                        'Name' => $name,
                        'Type' => $type,
                        'Value' => $value,
                        'Line' => 'default',
                        'TTL' => 600, // dynv6 doesn't expose TTL in API
                        'MX' => $type == 'MX' && isset($row['priority']) ? $row['priority'] : null,
                        'Status' => '1',
                        'Weight' => null,
                        'Remark' => null,
                        'UpdateTime' => null,
                    ];
                }
            }
            
            // 过滤
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
        // 验证记录值不能为空
        if (empty($Value) && $Value !== '0') {
            $this->setError('记录值不能为空');
            return false;
        }
        
        $zoneID = $this->getZoneID();
        if ($zoneID === false) {
            return false;
        }
        
        // 转换名称：只处理 @ -> domain，其他保持原样传给 API（dynv6 会自动扩展相对名称）
        if ($Name == '@' || empty($Name)) {
            $fqdn = $this->domain;
        } else {
            $fqdn = $Name;
        }
        
        // 处理记录值：CNAME/MX 指向的域名需为 FQDN（末尾加点）
        if ($Type == 'CNAME' || $Type == 'MX') {
            if (!empty($Value) && substr($Value, -1) !== '.') {
                // 仅对含点的完整域名补末尾点，避免被 dynv6 当作相对名称拼接；
                // 纯相对名称（如 "mail"）交给 dynv6 自动扩展，与 name 处理保持一致
                if (strpos($Value, '.') !== false) {
                    $Value .= '.';
                }
            }
        }
        
        $params = [
            'name' => $fqdn,
            'type' => $Type,
            'data' => $Value,
        ];
        
        // MX记录需要priority字段
        if ($Type == 'MX') {
            $params['priority'] = intval($MX);
        }
        
        $data = $this->send_request('POST', '/zones/' . $zoneID . '/records', $params);
        if ($data && isset($data['id'])) {
            return $data['id'];
        }
        return false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = 'default', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        // 验证记录值不能为空
        if (empty($Value) && $Value !== '0') {
            $this->setError('记录值不能为空');
            return false;
        }
        
        $zoneID = $this->getZoneID();
        if ($zoneID === false) {
            return false;
        }
        
        // 转换名称：只处理 @ -> domain，其他保持原样传给 API（dynv6 会自动扩展相对名称）
        if ($Name == '@' || empty($Name)) {
            $fqdn = $this->domain;
        } else {
            $fqdn = $Name;
        }
        
        // 处理记录值：CNAME/MX 指向的域名需为 FQDN（末尾加点）
        if ($Type == 'CNAME' || $Type == 'MX') {
            if (!empty($Value) && substr($Value, -1) !== '.') {
                // 仅对含点的完整域名补末尾点，避免被 dynv6 当作相对名称拼接；
                // 纯相对名称（如 "mail"）交给 dynv6 自动扩展，与 name 处理保持一致
                if (strpos($Value, '.') !== false) {
                    $Value .= '.';
                }
            }
        }
        
        $params = [
            'name' => $fqdn,
            'type' => $Type,
            'data' => $Value,
        ];
        
        // MX记录需要priority字段
        if ($Type == 'MX') {
            $params['priority'] = intval($MX);
        }
        
        $data = $this->send_request('PATCH', '/zones/' . $zoneID . '/records/' . $RecordId, $params);
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
        $zoneID = $this->getZoneID();
        if ($zoneID === false) {
            return false;
        }
        
        $data = $this->send_request('DELETE', '/zones/' . $zoneID . '/records/' . $RecordId);
        return $data !== false;
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

    //获取域名最低TTL
    public function getMinTTL()
    {
        return false;
    }

    public function addDomain($Domain)
    {
        return false;
    }

    private function send_request($method, $path, $params = null)
    {
        $url = $this->baseUrl . $path;
        
        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
        ];
        
        $body = null;
        if ($method == 'GET' || $method == 'DELETE') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            // POST/PATCH: 发送JSON
            if ($params) {
                $headers['Content-Type'] = 'application/json';
                $body = json_encode($params);
            }
        }

        try {
            $response = http_request($url, $body, null, null, $headers, $this->proxy, $method, 30);
        } catch (Exception $e) {
            $this->setError($e->getMessage());
            return false;
        }

        $statusCode = $response['code'] ?? 0;
        
        // 204 No Content（删除成功）
        if ($statusCode == 204) {
            return true;
        }
        
        // 尝试解析JSON响应
        $arr = json_decode($response['body'], true);
        
        // 2xx成功
        if ($statusCode >= 200 && $statusCode < 300) {
            return $arr !== null ? $arr : true;
        }
        
        // 错误处理
        if (isset($arr['error'])) {
            $this->setError($arr['error']);
        } elseif (isset($arr['message'])) {
            $this->setError($arr['message']);
        } else {
            $this->setError('HTTP ' . $statusCode . ': ' . $response['body']);
        }
        
        return false;
    }

    private function setError($message)
    {
        $this->error = $message;
    }
}

