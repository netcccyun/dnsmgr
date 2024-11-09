<?php

namespace app\lib\dns;

use app\lib\DnsInterface;

class dnspod implements DnsInterface
{
    private $SecretId;
    private $SecretKey;
    private $endpoint = "dnspod.tencentcloudapi.com";
    private $service = "dnspod";
    private $version = "2021-03-23";
    private $error;
    private $domain;
    private $domainid;
    private $domainInfo;

    public function __construct($config)
    {
        $this->SecretId = $config['ak'];
        $this->SecretKey = $config['sk'];
        $this->domain = $config['domain'];
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
        $action = 'DescribeDomainList';
        $offset = ($PageNumber - 1) * $PageSize;
        $param = ['Offset' => $offset, 'Limit' => $PageSize, 'Keyword' => $KeyWord];
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            $list = [];
            foreach ($data['DomainList'] as $row) {
                $list[] = [
                    'DomainId' => $row['DomainId'],
                    'Domain' => $row['Name'],
                    'RecordCount' => $row['RecordCount'],
                ];
            }
            return ['total' => $data['DomainCountInfo']['DomainTotal'], 'list' => $list];
        }
        return false;
    }

    //获取解析记录列表
    public function getDomainRecords($PageNumber = 1, $PageSize = 20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null)
    {
        $offset = ($PageNumber - 1) * $PageSize;
        if (!isNullOrEmpty($Status) || !isNullOrEmpty($Value)) {
            $action = 'DescribeRecordFilterList';
            $param = ['Domain' => $this->domain, 'Offset' => $offset, 'Limit' => $PageSize, 'RecordValue' => $Value];
            if (!isNullOrEmpty($SubDomain)) $param['SubDomain'] = $SubDomain;
            if (!isNullOrEmpty($KeyWord)) $param['Keyword'] = $KeyWord;
            if (!isNullOrEmpty($Value)) $param['RecordValue'] = $Value;
            if (!isNullOrEmpty($Status)) {
                $Status = $Status == '1' ? 'ENABLE' : 'DISABLE';
                $param['RecordStatus'] = [$Status];
            }
            if (!isNullOrEmpty($Type)) $param['RecordType'] = [$this->convertType($Type)];
            if (!isNullOrEmpty($Line)) $param['RecordLine'] = [$Line];
        } else {
            $action = 'DescribeRecordList';
            $param = ['Domain' => $this->domain, 'Subdomain' => $SubDomain, 'RecordType' => $this->convertType($Type), 'RecordLineId' => $Line, 'Keyword' => $KeyWord, 'Offset' => $offset, 'Limit' => $PageSize];
        }
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            $list = [];
            foreach ($data['RecordList'] as $row) {
                //if($row['Name'] == '@' && $row['Type'] == 'NS') continue;
                $list[] = [
                    'RecordId' => $row['RecordId'],
                    'Domain' => $this->domain,
                    'Name' => $row['Name'],
                    'Type' => $this->convertTypeId($row['Type']),
                    'Value' => $row['Value'],
                    'Line' => $row['LineId'],
                    'TTL' => $row['TTL'],
                    'MX' => $row['MX'],
                    'Status' => $row['Status'] == 'ENABLE' ? '1' : '0',
                    'Weight' => $row['Weight'],
                    'Remark' => $row['Remark'],
                    'UpdateTime' => $row['UpdatedOn'],
                ];
            }
            return ['total' => $data['RecordCountInfo']['TotalCount'], 'list' => $list];
        } elseif ($this->error == '记录列表为空。' || $this->error == 'No records on the list.') {
            return ['total' => 0, 'list' => []];
        }
        return false;
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
        $action = 'DescribeRecord';
        $param = ['Domain' => $this->domain, 'RecordId' => intval($RecordId)];
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            return [
                'RecordId' => $data['RecordInfo']['Id'],
                'Domain' => $this->domain,
                'Name' => $data['RecordInfo']['SubDomain'],
                'Type' => $this->convertTypeId($data['RecordInfo']['RecordType']),
                'Value' => $data['RecordInfo']['Value'],
                'Line' => $data['RecordInfo']['RecordLineId'],
                'TTL' => $data['RecordInfo']['TTL'],
                'MX' => $data['RecordInfo']['MX'],
                'Status' => $data['RecordInfo']['Enabled'] == 1 ? '1' : '0',
                'Weight' => $data['RecordInfo']['Weight'],
                'Remark' => $data['RecordInfo']['Remark'],
                'UpdateTime' => $data['RecordInfo']['UpdatedOn'],
            ];
        }
        return false;
    }

    //添加解析记录
    public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $action = 'CreateRecord';
        $param = ['Domain' => $this->domain, 'SubDomain' => $Name, 'RecordType' => $this->convertType($Type), 'Value' => $Value, 'RecordLine' => $Line, 'RecordLineId' => $this->convertLineCode($Line), 'TTL' => intval($TTL), 'Weight' => $Weight];
        if ($Type == 'MX') $param['MX'] = intval($MX);
        $data = $this->send_reuqest($action, $param);
        return is_array($data) ? $data['RecordId'] : false;
    }

    //修改解析记录
    public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Weight = null, $Remark = null)
    {
        $action = 'ModifyRecord';
        $param = ['Domain' => $this->domain, 'RecordId' => intval($RecordId), 'SubDomain' => $Name, 'RecordType' => $this->convertType($Type), 'Value' => $Value, 'RecordLine' => $Line, 'RecordLineId' => $this->convertLineCode($Line), 'TTL' => intval($TTL), 'Weight' => $Weight];
        if ($Type == 'MX') $param['MX'] = intval($MX);
        $data = $this->send_reuqest($action, $param);
        return is_array($data);
    }

    //修改解析记录备注
    public function updateDomainRecordRemark($RecordId, $Remark)
    {
        $action = 'ModifyRecordRemark';
        $param = ['Domain' => $this->domain, 'RecordId' => intval($RecordId), 'Remark' => $Remark];
        $data = $this->send_reuqest($action, $param);
        return is_array($data);
    }

    //删除解析记录
    public function deleteDomainRecord($RecordId)
    {
        $action = 'DeleteRecord';
        $param = ['Domain' => $this->domain, 'RecordId' => intval($RecordId)];
        $data = $this->send_reuqest($action, $param);
        return is_array($data);
    }

    //设置解析记录状态
    public function setDomainRecordStatus($RecordId, $Status)
    {
        $Status = $Status == '1' ? 'ENABLE' : 'DISABLE';
        $action = 'ModifyRecordStatus';
        $param = ['Domain' => $this->domain, 'RecordId' => intval($RecordId), 'Status' => $Status];
        $data = $this->send_reuqest($action, $param);
        return is_array($data);
    }

    //获取解析记录操作日志
    public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null)
    {
        $action = 'DescribeDomainLogList';
        $offset = ($PageNumber - 1) * $PageSize;
        $param = ['Domain' => $this->domain, 'Offset' => $offset, 'Limit' => $PageSize];
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            $list = [];
            foreach ($data['LogList'] as $row) {
                $list[] = ['time' => substr($row, 0, strpos($row, '(')), 'ip' => substr($row, strpos($row, '(') + 1, strpos($row, ')') - strpos($row, '(') - 1), 'data' => substr($row, strpos($row, ')') + 1, strpos($row, ' Uin:') - strpos($row, ')') - 1)];
            }
            return ['total' => $data['TotalCount'], 'list' => $list];
        }
        return false;
    }

    //获取解析线路列表
    public function getRecordLine()
    {
        $action = 'DescribeRecordLineCategoryList';
        $param = ['Domain' => $this->domain];
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            $list = [];
            $this->processLineList($list, $data['LineList'], null);
            return $list;
        } else {
            $data = $this->getRecordLineByGrade();
            if ($data) {
                $list = [];
                foreach ($data as $row) {
                    $list[$row['LineId']] = ['name' => $row['Name'], 'parent' => null];
                }
                return $list;
            }
        }
        return false;
    }

    private function processLineList(&$list, $line_list, $parent)
    {
        foreach ($line_list as $row) {
            if (isNullOrEmpty($row['LineId'])) $row['LineId'] = 'N.' . $row['LineName'];
            if ($row['Useful'] && !isset($list[$row['LineId']])) {
                $list[$row['LineId']] = ['name' => $row['LineName'], 'parent' => $parent];
                if ($row['SubGroup']) {
                    $this->processLineList($list, $row['SubGroup'], $row['LineId']);
                }
            }
        }
    }

    //获取域名概览信息
    public function getDomainInfo()
    {
        $action = 'DescribeDomain';
        $param = ['Domain' => $this->domain];
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            $this->domainInfo = $data['DomainInfo'];
            return $data['DomainInfo'];
        }
        return false;
    }

    //获取域名权限
    public function getDomainPurview()
    {
        $action = 'DescribeDomainPurview';
        $param = ['Domain' => $this->domain];
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            return $data['PurviewList'];
        }
        return false;
    }

    //获取域名最低TTL
    public function getMinTTL()
    {
        if ($this->domainInfo) {
            return $this->domainInfo['TTL'];
        }
        $PurviewList = $this->getDomainPurview();
        if ($PurviewList) {
            foreach ($PurviewList as $row) {
                if ($row['Name'] == '记录 TTL 最低' || $row['Name'] == 'Min TTL value') {
                    return intval($row['Value']);
                }
            }
        }
        return false;
    }

    //获取等级允许的线路
    public function getRecordLineByGrade()
    {
        $action = 'DescribeRecordLineList';
        $param = ['Domain' => $this->domain, 'DomainGrade' => ''];
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            $line_list = $data['LineList'];
            if (!empty($data['LineGroupList'])) {
                foreach ($data['LineGroupList'] as $row) {
                    $line_list[] = ['Name' => $row['Name'], 'LineId' => $row['LineId']];
                }
            }
            return $line_list;
        }
        return false;
    }

    //获取用户信息
    public function getAccountInfo()
    {
        $action = 'DescribeUserDetail';
        $param = [];
        $data = $this->send_reuqest($action, $param);
        if ($data) {
            return $data['UserInfo'];
        }
        return false;
    }

    private function convertLineCode($line)
    {
        $convert_dict = ['default' => '0', 'unicom' => '10=1', 'telecom' => '10=0', 'mobile' => '10=3', 'edu' => '10=2', 'oversea' => '3=0', 'btvn' => '10=22', 'search' => '80=0', 'internal' => '7=0'];
        if (array_key_exists($line, $convert_dict)) {
            return $convert_dict[$line];
        }
        return $line;
    }

    private function convertType($type)
    {
        $convert_dict = ['REDIRECT_URL' => '显性URL', 'FORWARD_URL' => '隐性URL'];
        if (array_key_exists($type, $convert_dict)) {
            return $convert_dict[$type];
        }
        return $type;
    }

    private function convertTypeId($type)
    {
        $convert_dict = ['显性URL' => 'REDIRECT_URL', '隐性URL' => 'FORWARD_URL'];
        if (array_key_exists($type, $convert_dict)) {
            return $convert_dict[$type];
        }
        return $type;
    }


    private function send_reuqest($action, $param)
    {
        $param = array_filter($param, function ($a) { return $a !== null;});
        if (!$param) $param = (object)[];
        $payload = json_encode($param);
        $time = time();
        $authorization = $this->generateSign($payload, $time);
        $header = [
            'Authorization: '.$authorization,
            'Content-Type: application/json; charset=utf-8',
            'X-TC-Action: '.$action,
            'X-TC-Timestamp: '.$time,
            'X-TC-Version: '.$this->version,
        ];
        return $this->curl_post($payload, $header);
    }

    private function generateSign($payload, $time)
    {
        $algorithm = "TC3-HMAC-SHA256";

        // step 1: build canonical request string
        $httpRequestMethod = "POST";
        $canonicalUri = "/";
        $canonicalQueryString = "";
        $canonicalHeaders = "content-type:application/json; charset=utf-8\n"."host:".$this->endpoint."\n";
        $signedHeaders = "content-type;host";
        $hashedRequestPayload = hash("SHA256", $payload);
        $canonicalRequest = $httpRequestMethod."\n"
            .$canonicalUri."\n"
            .$canonicalQueryString."\n"
            .$canonicalHeaders."\n"
            .$signedHeaders."\n"
            .$hashedRequestPayload;

        // step 2: build string to sign
        $date = gmdate("Y-m-d", $time);
        $credentialScope = $date."/".$this->service."/tc3_request";
        $hashedCanonicalRequest = hash("SHA256", $canonicalRequest);
        $stringToSign = $algorithm."\n"
            .$time."\n"
            .$credentialScope."\n"
            .$hashedCanonicalRequest;

        // step 3: sign string
        $secretDate = hash_hmac("SHA256", $date, "TC3".$this->SecretKey, true);
        $secretService = hash_hmac("SHA256", $this->service, $secretDate, true);
        $secretSigning = hash_hmac("SHA256", "tc3_request", $secretService, true);
        $signature = hash_hmac("SHA256", $stringToSign, $secretSigning);

        // step 4: build authorization
        $authorization = $algorithm
            ." Credential=".$this->SecretId."/".$credentialScope
            .", SignedHeaders=content-type;host, Signature=".$signature;

        return $authorization;
    }

    private function curl_post($payload, $header)
    {
        $url = 'https://'.$this->endpoint.'/';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno) {
            $this->setError('Curl error: ' . curl_error($ch));
        }
        curl_close($ch);
        if ($errno) return false;

        $arr = json_decode($response, true);
        if ($arr) {
            if (isset($arr['Response']['Error'])) {
                $this->setError($arr['Response']['Error']['Message']);
                return false;
            } else {
                return $arr['Response'];
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
