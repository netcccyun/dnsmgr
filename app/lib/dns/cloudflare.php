<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Cloudflare\API\Auth\APIKey;
use Cloudflare\API\Adapter\Guzzle;
use Cloudflare\API\Endpoints\DNS;
use Cloudflare\API\Endpoints\Zones;

class cloudflare implements DnsInterface
{
    private $Email;
    private $ApiKey;
    private $baseUrl = 'https://api.cloudflare.com/client/v4';
    private $error;
    private $domain;
    private $domainid;

    protected DNS $dns;

    protected Zones $zone;

    public function __construct($config)
    {
        $this->Email = $config['ak'];
        $this->ApiKey = $config['sk'];
        $this->domain = $config['domain'];
        $this->domainid = $config['domainid'];
        $key = new APIKey($this->ApiKey);
        $adapter = new Guzzle($key);
        $this->zone = new Zones($adapter);
        $this->dns = new DNS($adapter);
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
        $data = $this->objectToArray($this->zone->listZones($KeyWord !== null ? $KeyWord : '', '', $PageNumber, $PageSize));
        if ($data) {
            $list = [];
            foreach ($data['result'] as $row) {
                $list[] = [
                    'DomainId' => $row['id'],
                    'Domain' => $row['name'],
                    'RecordCount' => 0,
                ];
            }
            return ['total' => $data['result_info']['total_count'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = '', $SubDomain = '', $Value = null, $Type = '', $Line = '', $Status = '')
    {
        if (!isNullOrEmpty($SubDomain)) {
            if ($SubDomain == '@') {
                $SubDomain = $this->domain;
            } else {
                $SubDomain .= '.'.$this->domain;
            }
        } else {
            $SubDomain = '';
        }
        if (!isNullOrEmpty($Value)) {
            $KeyWord = $Value;
        }
        $data = $this->objectToArray($this->dns->listRecords($this->domainid, $Type == null ? '' : $Type, $SubDomain, $KeyWord, $PageNumber, $PageSize, '', '', $Line == '1'));
        if ($data) {
            $list = [];
            foreach ($data['result'] as $row) {
                $name = $row['zone_name'] == $row['name'] ? '@' : str_replace('.'.$row['zone_name'], '', $row['name']);
                $list[] = [
                    'RecordId' => $row['id'],
                    'Domain' => $row['zone_name'],
                    'Name' => $name,
                    'Type' => $row['type'],
                    'Value' => $row['content'],
                    'Line' => $row['proxied'] ? '1' : '0',
                    'TTL' => $row['ttl'],
                    'MX' => $row['priority'] ?? null,
                    'Status' => '1',
                    'Weight' => null,
                    'Remark' => $row['comment'],
                    'UpdateTime' => $row['modified_on'],
                ];
            }
            return ['total' => $data['result_info']['total_count'], 'list' => $list];
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
        $data = $this->send_reuqest('GET', '/zones/'.$this->domainid.'/dns_records/'.$RecordId);
        if ($data) {
            $name = $data['result']['zone_name'] == $data['result']['name'] ? '@' : str_replace('.'.$data['result']['zone_name'], '', $data['result']['name']);
            return [
                'RecordId' => $data['result']['id'],
                'Domain' => $data['result']['zone_name'],
                'Name' => str_replace('.'.$data['result']['zone_name'], '', $data['result']['name']),
                'Type' => $data['result']['type'],
                'Value' => $data['result']['content'],
                'Line' => $data['result']['proxied'] ? '1' : '0',
                'TTL' => $data['result']['ttl'],
                'MX' => $data['result']['priority'] ?? null,
                'Status' => '1',
                'Weight' => null,
                'Remark' => $data['result']['comment'],
                'UpdateTime' => $data['result']['modified_on'],
            ];
        }
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $response = $this->dns->addRecord($this->domainid, $this->convertType($Type), $Name, $Type == 'CAA' || $Type == 'SRV' ? '' : $Value, $TTL, $Line == '1', $Type == 'MX' ? $MX : '', $Type == 'CAA' || $Type == 'SRV' ? $this->convertValue($Value, $Type) : [], $Remark != null ? $Remark : '');
        $data = $this->objectToArray($response);
        return is_array($data) ? $data['result']['id'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $param = ['name' => $Name, 'type' => $this->convertType($Type), 'content' => $Value, 'proxied' => $Line == '1', 'ttl' => intval($TTL), 'comment' => $Remark];
        if ($Type == 'MX') {
            $param['priority'] = intval($MX);
        }
        if ($Type == 'CAA' || $Type == 'SRV') {
            unset($param['content']);
            $param['data'] = $this->convertValue($Value, $Type);
        }
        $data = $this->objectToArray($this->dns->updateRecord($this->domainid, $RecordId, $param));
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
        $data = $this->objectToArray($this->dns->deleteRecord($this->domainid, $RecordId));
        return is_array($data);
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
        return ['0' => ['name' => '仅DNS', 'parent' => null], '1' => ['name' => '已代理', 'parent' => null]];
    }

    //获取域名信息
    public function getDomainInfo()
    {
        $data = $this->send_reuqest('GET', '/zones/'.$this->domainid);
        if ($data) {
            return $data['result'];
        }
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return false;
    }

    private function convertType($type)
    {
        $convert_dict = ['REDIRECT_URL' => 'URI', 'FORWARD_URL' => 'URI'];
        if (array_key_exists($type, $convert_dict)) {
            return $convert_dict[$type];
        }
        return $type;
    }

    private function convertValue($value, $type)
    {
        if ($type == 'SRV') {
            $arr = explode(' ', $value);
            if (count($arr) > 3) {
                $data = [
                    'priority' => intval($arr[0]),
                    'weight' => intval($arr[1]),
                    'port' => intval($arr[2]),
                    'target' => $arr[3],
                ];
            } else {
                $data = [
                    'weight' => intval($arr[0]),
                    'port' => intval($arr[1]),
                    'target' => $arr[2],
                ];
            }
        } elseif ($type == 'CAA') {
            $arr = explode(' ', $value);
            $data = [
                'flags' => intval($arr[0]),
                'tag' => $arr[1],
                'value' => trim($arr[2], '"'),
            ];
        }
        return $data;
    }

    private function send_reuqest($method, $path, $params = null)
    {
        $url = $this->baseUrl . $path;

        if (preg_match('/^[0-9a-z]+$/i', $this->ApiKey)) {
            $headers = [
                'X-Auth-Email: '.$this->Email,
                'X-Auth-Key: '.$this->ApiKey,
            ];
        } else {
            $headers = [
                'Authorization: Bearer '.$this->ApiKey,
            ];
        }

        $body = '';
        if ($method == 'GET' || $method == 'DELETE') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            $body = json_encode($params);
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
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
            if ($arr['success']) {
                return $arr;
            } else {
                $this->setError(isset($arr['errors'][0]) ? $arr['errors'][0]['message'] : '未知错误');
                return false;
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

    private function objectToArray($object): array
    {
        return json_decode(json_encode($object), true);
    }

}
