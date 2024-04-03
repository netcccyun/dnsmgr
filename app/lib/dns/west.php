<?php
namespace app\lib\dns;

use app\lib\DnsInterface;

class west implements DnsInterface {
	private $username;
	private $api_password;
	private $baseUrl = 'https://api.west.cn/api/v2';
	private $error;
	private $domain;
	private $domainid;

	function __construct($config){
		$this->username = $config['ak'];
		$this->api_password = $config['sk'];
		$this->domain = $config['domain'];
	}

	public function getError(){
		return $this->error;
	}

	public function check(){
		if($this->getDomainList() != false){
			return true;
		}
		return false;
	}

	//获取域名列表
	public function getDomainList($KeyWord=null, $PageNumber=1, $PageSize=20){
		$param = ['page' => $PageNumber, 'limit' => $PageSize, 'domain' => $KeyWord];
		$data = $this->execute('/domain/?act=getdomains', $param);
		if($data){
			$list = [];
			foreach($data['items'] as $row){
				$list[] = [
					'DomainId' => $row['domain'],
					'Domain' => $row['domain'],
					'RecordCount' => 0,
				];
			}
			return ['total' => $data['total'], 'list' => $list];
		}
		return false;
	}

	//获取解析记录列表
	public function getDomainRecords($PageNumber=1, $PageSize=20, $KeyWord = null, $SubDomain = null, $Type = null, $Line = null, $Status = null){
		$param = ['act' => 'dnsrec.list', 'domain' => $this->domain, 'record_type' => $Type, 'record_line' => $Line, 'hostname' => $KeyWord, 'pageno' => $PageNumber, 'limit' => $PageSize];
		if(!isNullOrEmpty(($SubDomain))){
			$param['hostname'] = $SubDomain;
		}
		$data = $this->execute2('/domain/dns/', $param);
		if($data){
			$list = [];
			foreach($data['items'] as $row){
				$list[] = [
					'RecordId' => $row['record_id'],
					'Domain' => $this->domain,
					'Name' => $row['hostname'],
					'Type' => $row['record_type'],
					'Value' => $row['record_value'],
					'Line' => $row['record_line'],
					'TTL' => $row['record_ttl'],
					'MX' => $row['record_mx'],
					'Status' => $row['pause'] == 1 ? '0' : '1',
					'Weight' => null,
					'Remark' => null,
					'UpdateTime' => null,
				];
			}
			return ['total' => $data['total'], 'list' => $list];
		}
		return false;
	}

	//获取子域名解析记录列表
	public function getSubDomainRecords($SubDomain, $PageNumber=1, $PageSize=20, $Type = null, $Line = null){
		$domain_arr = explode('.', $SubDomain);
		$domain = $domain_arr[count($domain_arr)-2].'.'.$domain_arr[count($domain_arr)-1];
		$subdomain = rtrim(str_replace($domain,'',$SubDomain),'.');
		if($subdomain == '')$subdomain='@';
		return $this->getDomainRecords($PageNumber, $PageSize, null, $subdomain, $Type, $Line);
	}

	//获取解析记录详细信息
	public function getDomainRecordInfo($RecordId){
		return false;
	}

	//添加解析记录
	public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Remark = null){
		$param = ['act' => 'dnsrec.add', 'domain' => $this->domain, 'hostname' => $Name, 'record_type' => $this->convertType($Type), 'record_value' => $Value, 'record_level' => $MX, 'record_ttl' => intval($TTL), 'record_line' => $Line];
		$data = $this->execute2('/domain/dns/', $param);
		return is_array($data) ? $data['record_id'] : false;
	}

	//修改解析记录
	public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Remark = null){
		$param = ['act' => 'dnsrec.modify', 'domain' => $this->domain, 'record_id' => $RecordId, 'record_type' => $this->convertType($Type), 'record_value' => $Value, 'record_level' => $MX, 'record_ttl' => intval($TTL), 'record_line' => $Line];
		$data = $this->execute2('/domain/dns/', $param);
		return is_array($data);
	}

	//修改解析记录备注
	public function updateDomainRecordRemark($RecordId, $Remark){
		return false;
	}

	//删除解析记录
	public function deleteDomainRecord($RecordId){
		$param = ['act' => 'dnsrec.remove', 'domain' => $this->domain, 'record_id' => $RecordId];
		$data = $this->execute2('/domain/dns/', $param);
		return is_array($data);
	}

	//设置解析记录状态
	public function setDomainRecordStatus($RecordId, $Status){
		return false;
	}

	//获取解析记录操作日志
	public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null){
		return false;
	}

	//获取解析线路列表
	public function getRecordLine(){
		return [
			''=>['name'=>'默认', 'parent'=>null],
			'LTEL'=>['name'=>'电信', 'parent'=>null],
			'LCNC'=>['name'=>'联通', 'parent'=>null],
			'LMOB'=>['name'=>'移动', 'parent'=>null],
			'LEDU'=>['name'=>'教育网', 'parent'=>null],
			'LSEO'=>['name'=>'搜索引擎', 'parent'=>null],
			'LFOR'=>['name'=>'境外', 'parent'=>null],
		];
	}

	//获取域名信息
	public function getDomainInfo(){
		return false;
	}

	//获取域名最低TTL
	public function getMinTTL(){
		return false;
	}

	private function convertType($type){
		return $type;
	}

	private function execute($path, $params){
		$params['username'] = $this->username;
		$params['time'] = $this->getMillisecond();
		$params['token'] = md5($this->username.$this->api_password.$params['time']);
		$response = $this->curl($path, $params);
		$response = mb_convert_encoding($response, 'UTF-8', 'GBK');
		$arr=json_decode($response,true);
		if($arr){
			if($arr['result'] == 200){
				return $arr['data'];
			}else{
				$this->setError($arr['msg']);
				return false;
			}
		}else{
			$this->setError('返回数据解析失败');
			return false;
		}
	}

	private function execute2($path, $params){
		$params['username'] = $this->username;
		$params['apikey'] = md5($this->api_password);
		$response = $this->curl($path, $params);
		$response = mb_convert_encoding($response, 'UTF-8', 'GBK');
		$arr=json_decode($response,true);
		if($arr){
			if($arr['code'] == 200){
				return $arr['body'];
			}else{
				$this->setError($arr['msg']);
				return false;
			}
		}else{
			$this->setError('返回数据解析失败');
			return false;
		}
	}

	private function curl($path, $params = null){
		$url = $this->baseUrl . $path;
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		if ($params) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		if ($errno) {
			$this->setError('Curl error: ' . curl_error($ch));
		}
		curl_close($ch);
		if ($errno) return false;
		return $response;
	}

	private function getMillisecond()
    {
        list($s1, $s2) = explode(' ', microtime());
        return (float)sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
    }

	private function setError($message){
		$this->error = $message;
		//file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
	}
}