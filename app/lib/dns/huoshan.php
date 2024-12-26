<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use app\lib\client\Volcengine;
use Exception;

class huoshan implements DnsInterface
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint = "open.volcengineapi.com";
    private $service = "DNS";
    private $version = "2018-08-01";
    private $region = "cn-north-1";
    private $error;
    private $domain;
    private $domainid;
    private $domainInfo;
    private Volcengine $client;

    private static $trade_code_list = [
        'free_inner' => ['level' => 1, 'name' => '免费版', 'ttl' => 600],
        'professional_inner' => ['level' => 2, 'name' => '专业版', 'ttl' => 300],
        'enterprise_inner' => ['level' => 3, 'name' => '企业版', 'ttl' => 60],
        'ultimate_inner' => ['level' => 4, 'name' => '旗舰版', 'ttl' => 1],
        'ultimate_exclusive_inner' => ['level' => 5, 'name' => '尊享版', 'ttl' => 1],
    ];

    public function __construct($config)
    {
        $this->AccessKeyId = $config['ak'];
        $this->SecretAccessKey = $config['sk'];
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, $this->endpoint, $this->service, $this->version, $this->region, $proxy);
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
        $query = ['PageNumber' => $PageNumber, 'PageSize' => $PageSize, 'Key' => $KeyWord];
        $data = $this->send_request('GET', 'ListZones', $query);
        if ($data) {
            $list = [];
            if (!empty($data['Zones'])) {
                foreach ($data['Zones'] as $row) {
                    $list[] = [
                        'DomainId' => $row['ZID'],
                        'Domain' => $row['ZoneName'],
                        'RecordCount' => $row['RecordCount'],
                    ];
                }
            }
            return ['total' => $data['Total'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $query = ['ZID' => intval($this->domainid), 'PageNumber' => $PageNumber, 'PageSize' => $PageSize, 'SearchOrder' => 'desc'];
        if (!empty($SubDomain) || !empty($Type) || !empty($Line) || !empty($Value)) {
            $query += ['Host' => $SubDomain, 'Value' => $Value, 'Type' => $Type, 'Line' => $Line];
        } elseif (!empty($KeyWord)) {
            $query += ['Host' => $KeyWord];
        }
        $data = $this->send_request('GET', 'ListRecords', $query);
        if ($data) {
            $list = [];
            foreach ($data['Records'] as $row) {
                if ($row['Type'] == 'MX') list($row['MX'], $row['Value']) = explode(' ', $row['Value']);
                $list[] = [
                    'RecordId' => $row['RecordID'],
                    'Domain' => $this->domain,
                    'Name' => $row['Host'],
                    'Type' => $row['Type'],
                    'Value' => $row['Value'],
                    'Line' => $row['Line'],
                    'TTL' => $row['TTL'],
                    'MX' => isset($row['MX']) ? $row['MX'] : null,
                    'Status' => $row['Enable'] ? '1' : '0',
                    'Weight' => $row['Weight'],
                    'Remark' => $row['Remark'],
                    'UpdateTime' => $row['UpdatedAt'],
                ];
            }
            return ['total' => $data['TotalCount'], 'list' => $list];
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
        $data = $this->send_request('GET', 'QueryRecord', ['RecordID' => $RecordId]);
        if ($data) {
            if ($data['name'] == $data['zone_name']) $data['name'] = '@';
            if ($data['Type'] == 'MX') list($data['MX'], $data['Value']) = explode(' ', $data['Value']);
            return [
                'RecordId' => $data['RecordID'],
                'Domain' => $this->domain,
                'Name' => $data['Host'],
                'Type' => $data['Type'],
                'Value' => $data['Value'],
                'Line' => $data['Line'],
                'TTL' => $data['TTL'],
                'MX' => isset($data['MX']) ? $data['MX'] : null,
                'Status' => $data['Enable'] ? '1' : '0',
                'Weight' => $data['Weight'],
                'Remark' => $data['Remark'],
                'UpdateTime' => $data['UpdatedAt'],
            ];
        }
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $params = ['ZID' => intval($this->domainid), 'Host' => $Name, 'Type' => $this->convertType($Type), 'Value' => $Value, 'Line' => $Line, 'TTL' => intval($TTL), 'Remark' => $Remark];
        if ($Type == 'MX') $params['Value'] = intval($MX) . ' ' . $Value;
        if ($Weight > 0) $params['Weight'] = $Weight;
        $data = $this->send_request('POST', 'CreateRecord', $params);
        return is_array($data) ? $data['RecordID'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $params = ['RecordID' => $RecordId, 'Host' => $Name, 'Type' => $this->convertType($Type), 'Value' => $Value, 'Line' => $Line, 'TTL' => intval($TTL), 'Remark' => $Remark];
        if ($Type == 'MX') $params['Value'] = intval($MX) . ' ' . $Value;
        if ($Weight > 0) $params['Weight'] = $Weight;
        $data = $this->send_request('POST', 'UpdateRecord', $params);
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
        $data = $this->send_request('POST', 'DeleteRecord', ['RecordID' => $RecordId]);
        return $data;
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $params = ['RecordID' => $RecordId, 'Enable' => $Status == '1'];
        $data = $this->send_request('POST', 'UpdateRecordStatus', $params);
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
        $domainInfo = $this->getDomainInfo();
        if (!$domainInfo) return false;
        $level = $this->getTradeInfo($domainInfo['TradeCode'])['level'];
        $data = $this->send_request('GET', 'ListLines', []);
        if ($data) {
            $list = [];
            $list['default'] = ['name' => '默认', 'parent' => null];
            foreach ($data['Lines'] as $row) {
                if ($row['Value'] == 'default') continue;
                if ($row['Level'] > $level) continue;
                $list[$row['Value']] = ['name' => $row['Name'], 'parent' => isset($row['FatherValue']) ? $row['FatherValue'] : null];
            }

            $data = $this->send_request('GET', 'ListCustomLines', []);
            if ($data && $data['TotalCount'] > 0) {
                $list['N.customer_lines'] = ['name' => '自定义线路', 'parent' => null];
                foreach ($data['CustomerLines'] as $row) {
                    $list[$row['Line']] = ['name' => $row['NameCN'], 'parent' => 'N.customer_lines'];
                }
            }

            return $list;
        }
        return false;
    }

    //获取域名概览信息
    public function getDomainInfo()
    {
        if (!empty($this->domainInfo)) return $this->domainInfo;
        $query = ['ZID' => intval($this->domainid)];
        $data = $this->send_request('GET', 'QueryZone', $query);
        if ($data) {
            $this->domainInfo = $data;
            return $data;
        }
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        $domainInfo = $this->getDomainInfo();
        if ($domainInfo) {
            $ttl = $this->getTradeInfo($domainInfo['TradeCode'])['ttl'];
            return $ttl;
        }
        return false;
    }

    private function convertType($type)
    {
        return $type;
    }

    private function getTradeInfo($trade_code)
    {
        if (array_key_exists($trade_code, self::$trade_code_list)) {
            $trade_code = $trade_code;
        } else {
            $trade_code = 'free_inner';
        }
        return self::$trade_code_list[$trade_code];
    }

    private function send_request($method, $action, $params = [])
    {
        try{
            return $this->client->request($method, $action, $params);
        }catch(Exception $e){
            $this->setError($e->getMessage());
            return false;
        }
    }

    private function setError($message)
    {
        $this->error = $message;
        //file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
    }
}
