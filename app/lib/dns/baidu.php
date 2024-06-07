<?php
namespace app\lib\dns;

use app\lib\DnsInterface;

class baidu implements DnsInterface {
	private $AccessKeyId;
	private $SecretAccessKey;
	private $endpoint = "dns.baidubce.com";
	private $error;
	private $domain;
	private $domainid;

	function __construct($config){
		$this->AccessKeyId = $config['ak'];
		$this->SecretAccessKey = $config['sk'];
		$this->domain = $config['domain'];
		$this->domainid = $config['domainid'];
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
		$query = ['name' => $KeyWord];
		$data = $this->send_reuqest('GET', '/v1/dns/zone', $query);
		if($data){
			$list = [];
			foreach($data['zones'] as $row){
				$list[] = [
					'DomainId' => $row['id'],
					'Domain' => rtrim($row['name'], '.'),
					'RecordCount' => 0,
				];
			}
			return ['total' => count($list), 'list' => $list];
		}
		return false;
	}

	//获取解析记录列表
	public function getDomainRecords($PageNumber=1, $PageSize=20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null){
		$marker = cookie('baidu_record_marker');
		$query = ['rr' => $KeyWord];
		if(!isNullOrEmpty(($SubDomain))){
			$param['rr'] = $SubDomain;
		}
		$data = $this->send_reuqest('GET', '/v1/dns/zone/'.$this->domain.'/record', $query);
		if($data){
			$list = [];
			foreach($data['records'] as $row){
				$list[] = [
					'RecordId' => $row['id'],
					'Domain' => $this->domain,
					'Name' => $row['rr'],
					'Type' => $row['type'],
					'Value' => $row['value'],
					'Line' => $row['line'],
					'TTL' => $row['ttl'],
					'MX' => $row['priority'],
					'Status' => $row['status'] == 'running' ? '1' : '0',
					'Weight' => null,
					'Remark' => $row['description'],
					'UpdateTime' => null,
				];
			}
			return ['total' => count($list), 'list' => $list];
		}
		return false;
	}

	//获取子域名解析记录列表
	public function getSubDomainRecords($SubDomain, $PageNumber=1, $PageSize=20, $Type = null, $Line = null){
		if($SubDomain == '')$SubDomain='@';
		return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
	}

	//获取解析记录详细信息
	public function getDomainRecordInfo($RecordId){
		$data = $this->send_reuqest('GET', '/v2.1/zones/'.$this->domainid.'/recordsets/'.$RecordId);
		if($data){
			return [
				'RecordId' => $data['id'],
				'Domain' => rtrim($data['zone_name'], '.'),
				'Name' => str_replace('.'.$data['zone_name'], '', $data['name']),
				'Type' => $data['type'],
				'Value' => $data['records'],
				'Line' => $data['line'],
				'TTL' => $data['ttl'],
				'MX' => $data['weight'],
				'Status' => $data['status'] == 'ACTIVE' ? '1' : '0',
				'Weight' => $data['weight'],
				'Remark' => $data['description'],
				'UpdateTime' => $data['updated_at'],
			];
		}
		return false;
	}

	//添加解析记录
	public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Remark = null){
		$params = ['rr' => $Name, 'type' => $this->convertType($Type), 'value' => $Value, 'line'=>$Line, 'ttl' => intval($TTL), 'description' => $Remark];
		if($Type == 'MX')$param['priority'] = intval($MX);
		$query = ['clientToken' => getSid()];
		return $this->send_reuqest('POST', '/v1/dns/zone/'.$this->domain.'/record', $query, $params);
	}

	//修改解析记录
	public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Remark = null){
		$params = ['rr' => $Name, 'type' => $this->convertType($Type), 'value' => $Value, 'line'=>$Line, 'ttl' => intval($TTL), 'description' => $Remark];
		if($Type == 'MX')$param['priority'] = intval($MX);
		$query = ['clientToken' => getSid()];
		return $this->send_reuqest('PUT', '/v1/dns/zone/'.$this->domain.'/record/'.$RecordId, $query, $params);
	}

	//修改解析记录备注
	public function updateDomainRecordRemark($RecordId, $Remark){
		return false;
	}

	//删除解析记录
	public function deleteDomainRecord($RecordId){
		$query = ['clientToken' => getSid()];
		return $this->send_reuqest('DELETE', '/v1/dns/zone/'.$this->domain.'/record/'.$RecordId, $query);
	}
	
	//设置解析记录状态
	public function setDomainRecordStatus($RecordId, $Status){
		$Status = $Status == '1' ? 'enable' : 'disable';
		$query = [$Status => '', 'clientToken' => getSid()];
		return $this->send_reuqest('PUT', '/v1/dns/zone/'.$this->domain.'/record/'.$RecordId, $query);
	}

	//获取解析记录操作日志
	public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null){
		return false;
	}

	//获取解析线路列表
	public function getRecordLine(){
		return [
			'default'=>['name'=>'默认', 'parent'=>null],
			'ct'=>['name'=>'电信', 'parent'=>null],
			'cnc'=>['name'=>'联通', 'parent'=>null],
			'cmnet'=>['name'=>'移动', 'parent'=>null],
			'edu'=>['name'=>'教育网', 'parent'=>null],
			'search'=>['name'=>'搜索引擎(百度)', 'parent'=>null],
		];
	}

	//获取域名概览信息
	public function getDomainInfo(){
		$res = $this->getDomainList($this->domain);
		if($res && !empty($res['list'])){
			return $res['list'][0];
		}
		return false;
	}

	//获取域名最低TTL
	public function getMinTTL(){
		return false;
	}

	private function convertType($type){
		return $type;
	}

	private function send_reuqest($method, $path, $query = null, $params = null){
		if(!empty($query)){
			$query = array_filter($query, function($a){ return $a!==null;});
		}
		if(!empty($params)){
			$params = array_filter($params, function($a){ return $a!==null;});
		}

		$time = time();
		$date = gmdate("Y-m-d\TH:i:s\Z", $time);
		$body = !empty($params) ? json_encode($params) : '';
		$headers = [
			'Host' => $this->endpoint,
			'x-bce-date' => $date,
		];
		if($body){
			$headers['Content-Type'] = 'application/json';
		}
		
		$authorization = $this->generateSign($method, $path, $query, $headers, $time);
		$headers['Authorization'] = $authorization;

		$url = 'https://'.$this->endpoint.$path;
		if(!empty($query)){
			$url .= '?'.http_build_query($query);
		}
		$header = [];
		foreach($headers as $key => $value){
			$header[] = $key.': '.$value;
		}
		return $this->curl($method, $url, $body, $header);
	}

	private function generateSign($method, $path, $query, $headers, $time){
		$algorithm = "bce-auth-v1";

		// step 1: build canonical request string
		$httpRequestMethod = $method;
		$canonicalUri = $this->getCanonicalUri($path);
		$canonicalQueryString = $this->getCanonicalQueryString($query);
		[$canonicalHeaders, $signedHeaders] = $this->getCanonicalHeaders($headers);
		$canonicalRequest = $httpRequestMethod."\n"
			.$canonicalUri."\n"
			.$canonicalQueryString."\n"
			.$canonicalHeaders;
		
		// step 2: calculate signing key
		$date = gmdate("Y-m-d\TH:i:s\Z", $time);
		$expirationInSeconds = 1800;
		$authString = $algorithm . '/' . $this->AccessKeyId . '/' . $date . '/' . $expirationInSeconds;
        $signingKey = hash_hmac('sha256', $authString, $this->SecretAccessKey);
		
		// step 3: sign string
		$signature = hash_hmac("sha256", $canonicalRequest, $signingKey);

		// step 4: build authorization
		$authorization = $authString . '/' . $signedHeaders . "/" . $signature;

		return $authorization;
	}

	private function escape($str)
    {
        $search = ['+', '*', '%7E'];
		$replace = ['%20', '%2A', '~'];
		return str_replace($search, $replace, urlencode($str));
    }

	private function getCanonicalUri($path)
	{
		if(empty($path)) return '/';
		$uri = str_replace('%2F', '/', $this->escape($path));
		if(substr($uri, 0, 1) !== '/') $uri = '/'.$uri;
		return $uri;
	}

	private function getCanonicalQueryString($parameters)
    {
		if(empty($parameters)) return '';
        ksort($parameters);
		$canonicalQueryString = '';
		foreach ($parameters as $key => $value) {
			if($key == 'authorization') continue;
			$canonicalQueryString .= '&' . $this->escape($key). '=' . $this->escape($value);
		}
        return substr($canonicalQueryString, 1);
    }

	private function getCanonicalHeaders($oldheaders){
		$headers = array();
        foreach ($oldheaders as $key => $value) {
            $headers[strtolower($key)] = trim($value);
        }
		ksort($headers);

		$canonicalHeaders = '';
		$signedHeaders = '';
		foreach ($headers as $key => $value) {
			$canonicalHeaders .= $this->escape($key) . ':' . $this->escape($value) . "\n";
			$signedHeaders .= $key . ';';
		}
		$canonicalHeaders = substr($canonicalHeaders, 0, -1);
		$signedHeaders = substr($signedHeaders, 0, -1);
		return [$canonicalHeaders, $signedHeaders];
	}

	private function curl($method, $url, $body, $header){
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		if(!empty($body)){
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}
		$response = curl_exec($ch);
		$errno = curl_errno($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if ($errno) {
			$this->setError('Curl error: ' . curl_error($ch));
		}
		curl_close($ch);
		if ($errno) return false;

		if(empty($response) && $httpCode == 200){
			return true;
		}
		$arr=json_decode($response,true);
		if($arr){
			if(isset($arr['code']) && isset($arr['message'])){
				$this->setError($arr['message']);
				return false;
			}else{
				return $arr;
			}
		}else{
			$this->setError('返回数据解析失败');
			return false;
		}
	}

	private function setError($message){
		$this->error = $message;
		//file_put_contents('logs.txt',date('H:i:s').' '.$message."\r\n", FILE_APPEND);
	}
}