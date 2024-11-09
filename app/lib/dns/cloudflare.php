<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use Cloudflare\API\Auth\APIKey;
use Cloudflare\API\Adapter\Guzzle;
use Cloudflare\API\Endpoints\DNS;
use Cloudflare\API\Endpoints\Zones;
use Guzzlehttp\Exception\BadResponseException;

class cloudflare implements DnsInterface
{
    private $ApiKey;
    private $error;
    private $domain;
    private $domainid;

    protected DNS $dns;

    protected Zones $zone;

    public function __construct($config)
    {
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
        try {
            $response = $this->dns->addRecord($this->domainid, $this->convertType($Type), $Name, $Type == 'CAA' || $Type == 'SRV' ? '' : $Value, $TTL, $Line == '1', $Type == 'MX' ? $MX : '', $Type == 'CAA' || $Type == 'SRV' ? $this->convertValue($Value, $Type) : [], $Remark != null ? $Remark : '');
        } catch (\Cloudflare\API\Adapter\ResponseException $e) {
            $this->setError($e->getMessage());
            return false;
        }

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
        try {
            $data = $this->objectToArray($this->dns->updateRecord($this->domainid, $RecordId, $param));
        } catch (\Cloudflare\API\Adapter\ResponseException $e) {
            $this->setError($e->getMessage());
            return false;
        }

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
        try {
            $data = $this->objectToArray($this->dns->deleteRecord($this->domainid, $RecordId));
        } catch (\Cloudflare\API\Adapter\ResponseException $e) {
            $this->setError($e->getMessage());
            return false;
        }

        return is_array($data);
    }

    // Cloudflare不支持设置解析记录状态
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
        $data = $this->objectToArray($this->zone->listZones($this->domainid));
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

    private function convertType($type): string
    {
        $convert_dict = ['REDIRECT_URL' => 'URI', 'FORWARD_URL' => 'URI'];
        if (array_key_exists($type, $convert_dict)) {
            return $convert_dict[$type];
        }
        return $type;
    }

    private function convertValue($value, $type): array
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
