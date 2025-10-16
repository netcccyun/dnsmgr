<?php

namespace app\lib\dns;

use app\lib\DnsInterface;
use app\lib\client\Jdcloud as JdcloudClient;
use Exception;

class jdcloud implements DnsInterface
{
    private $AccessKeyId;
    private $AccessKeySecret;
    private $endpoint = "domainservice.jdcloud-api.com";
    private $service = "domainservice";
    private $version = "v2";
    private $region = "cn-north-1";
    private $error;
    private $domain;
    private $domainid;
    private $domainInfo;
    private JdcloudClient $client;


    public function __construct($config)
    {
        $this->AccessKeyId = $config['ak'];
        $this->AccessKeySecret = $config['sk'];
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new JdcloudClient($this->AccessKeyId, $this->AccessKeySecret, $this->endpoint, $this->service, $this->region, $proxy);
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
        $query = ['pageNumber' => $PageNumber, 'pageSize' => $PageSize, 'domainName' => $KeyWord];
        $data = $this->send_request('GET', '/domain', $query);
        if ($data) {
            $list = [];
            if (!empty($data['dataList'])) {
                foreach ($data['dataList'] as $row) {
                    $list[] = [
                        'DomainId' => $row['id'],
                        'Domain' => $row['domainName'],
                        'RecordCount' => 0,
                    ];
                }
            }
            return ['total' => $data['totalCount'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        if ($PageSize > 99) $PageSize = 99;
        $query = ['pageNumber' => $PageNumber, 'pageSize' => $PageSize];
        if (!isNullOrEmpty($SubDomain)) {
            $SubDomain = strtolower($SubDomain);
            $query += ['search' => $SubDomain];
        } elseif (!isNullOrEmpty($KeyWord)) {
            $query += ['search' => $KeyWord];
        }
        $data = $this->send_request('GET', '/domain/'.$this->domainid.'/ResourceRecord', $query);
        if ($data) {
            $list = [];
            foreach ($data['dataList'] as $row) {
                if ($row['type'] == 'SRV') {
                    $row['hostValue'] = $row['mxPriority'].' '.$row['weight'].' '.$row['port'].' '.$row['hostValue'];
                }
                $list[] = [
                    'RecordId' => $row['id'],
                    'Domain' => $this->domain,
                    'Name' => $row['hostRecord'],
                    'Type' => $row['type'],
                    'Value' => $row['hostValue'],
                    'Line' => array_pop($row['viewValue']),
                    'TTL' => $row['ttl'],
                    'MX' => isset($row['mxPriority']) ? $row['mxPriority'] : null,
                    'Status' => $row['resolvingStatus'] == '2' ? '1' : '0',
                    'Weight' => $row['weight'],
                    'Remark' => null,
                    'UpdateTime' => date('Y-m-d H:i:s', $row['updateTime']),
                ];
            }
            if (!isNullOrEmpty($SubDomain) && !empty($list)) {
                $list = array_values(array_filter($list, function ($v) use ($SubDomain) {
                    return $v['Name'] == $SubDomain;
                }));
            }
            return ['total' => $data['totalCount'], 'list' => $list];
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
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $params = ['hostRecord' => $Name, 'type' => $this->convertType($Type), 'hostValue' => $Value, 'viewValue' => intval($Line), 'ttl' => intval($TTL)];
        if ($Type == 'MX') $params['mxPriority'] = intval($MX);
        if (!isNullOrEmpty($Weight)) $params['weight'] = intval($Weight);
        if ($Type == 'SRV') {
            $values = explode(' ', $Value);
            $params['mxPriority'] = intval($values[0]);
            $params['weight'] = intval($values[1]);
            $params['port'] = intval($values[2]);
            $params['hostValue'] = $values[3];
        }
        $data = $this->send_request('POST', '/domain/'.$this->domainid.'/ResourceRecord', ['req'=>$params]);
        return is_array($data) ? $data['dataList']['id'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $params = ['domainName'=>$this->domain, 'hostRecord' => $Name, 'type' => $this->convertType($Type), 'hostValue' => $Value, 'viewValue' => intval($Line), 'ttl' => intval($TTL)];
        if ($Type == 'MX') $params['mxPriority'] = intval($MX);
        if (!isNullOrEmpty($Weight)) $params['weight'] = intval($Weight);
        if ($Type == 'SRV') {
            $values = explode(' ', $Value);
            $params['mxPriority'] = intval($values[0]);
            $params['weight'] = intval($values[1]);
            $params['port'] = intval($values[2]);
            $params['hostValue'] = $values[3];
        }
        return $this->send_request('PUT', '/domain/'.$this->domainid.'/ResourceRecord/'.$RecordId, ['req'=>$params]);
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        return false;
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        return $this->send_request('DELETE', '/domain/'.$this->domainid.'/ResourceRecord/'.$RecordId);
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $params = ['action' => $Status == '1' ? 'enable' : 'disable'];
        $data = $this->send_request('PUT', '/domain/'.$this->domainid.'/ResourceRecord/'.$RecordId.'/status', $params);
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
        $packId = $domainInfo['packId'];
        $data = $this->send_request('GET', '/domain/'.$this->domainid.'/viewTree', ['packId'=>$packId, 'viewId'=>'0']);
        if ($data) {
            $list = [];
            $this->processLineList($list, $data['data'], null);
            return $list;
        }
        return false;
    }
    
    private function processLineList(&$list, $line_list, $parent)
    {
        foreach ($line_list as $row) {
            if ($row['disabled']) continue;
            if (!isset($list[$row['value']])) {
                $list[$row['value']] = ['name' => $row['label'], 'parent' => $parent];
                if (!$row['leaf'] && $row['children']) {
                    $this->processLineList($list, $row['children'], $row['value']);
                }
            }
        }
    }

    //获取域名概览信息
    public function getDomainInfo()
    {
        if (!empty($this->domainInfo)) return $this->domainInfo;
        $query = ['domainId' => intval($this->domainid)];
        $data = $this->send_request('GET', '/domain', $query);
        if ($data && $data['dataList']) {
            return $data['dataList'][0];
        }
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        return false;
    }

    public function addDomain($Domain)
    {
        $params = ['packId' => 0, 'domainName' => $Domain];
        $data = $this->send_request('POST', '/domain', $params);
        if ($data) {
            return ['id' => $data['data']['id'], 'name' => $data['data']['domainName']];
        }
        return false;
    }

    private function convertType($type)
    {
        $convert_dict = ['REDIRECT_URL' => 'EXPLICIT_URL', 'FORWARD_URL' => 'IMPLICIT_URL'];
        if (array_key_exists($type, $convert_dict)) {
            return $convert_dict[$type];
        }
        return $type;
    }

    private function send_request($method, $action, $params = [])
    {
        $path = '/'.$this->version.'/regions/'.$this->region.$action;
        try{
            return $this->client->request($method, $path, $params);
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
