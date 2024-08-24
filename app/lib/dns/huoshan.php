<?php
namespace app\lib\dns;

use app\lib\DnsInterface;

class huoshan implements DnsInterface {
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

	private static $trade_code_list = [
		'free_inner' => ['level' => 1, 'name' => '免费版', 'ttl' => 600],
		'professional_inner' => ['level' => 2, 'name' => '专业版', 'ttl' => 300],
		'enterprise_inner' => ['level' => 3, 'name' => '企业版', 'ttl' => 60],
		'ultimate_inner' => ['level' => 4, 'name' => '旗舰版', 'ttl' => 1],
		'ultimate_exclusive_inner' => ['level' => 5, 'name' => '尊享版', 'ttl' => 1],
	];

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
		$query = ['PageNumber' => $PageNumber, 'PageSize' => $PageSize, 'Key' => $KeyWord];
		$data = $this->send_reuqest('GET', 'ListZones', $query);
		if($data){
			$list = [];
			if(!empty($data['Zones'])){
				foreach($data['Zones'] as $row){
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
	public function getDomainRecords($PageNumber=1, $PageSize=20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null){
		$query = ['ZID' => intval($this->domainid), 'PageNumber' => $PageNumber, 'PageSize' => $PageSize, 'SearchOrder' => 'desc'];
		if(!empty($SubDomain) || !empty($Type) || !empty($Line) || !empty($Value)){
			$query += ['Host' => $SubDomain, 'Value' => $Value, 'Type' => $Type, 'Line' => $Line];
		}elseif(!empty($KeyWord)){
			$query += ['Host' => $KeyWord];
		}
		$data = $this->send_reuqest('GET', 'ListRecords', $query);
		if($data){
			$list = [];
			foreach($data['Records'] as $row){
				$list[] = [
					'RecordId' => $row['RecordID'],
					'Domain' => $this->domain,
					'Name' => $row['Host'],
					'Type' => $row['Type'],
					'Value' => $row['Value'],
					'Line' => $row['Line'],
					'TTL' => $row['TTL'],
					'MX' => $row['Weight'],
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
	public function getSubDomainRecords($SubDomain, $PageNumber=1, $PageSize=20, $Type = null, $Line = null){
		return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
	}

	//获取解析记录详细信息
	public function getDomainRecordInfo($RecordId){
		$data = $this->send_reuqest('GET', 'QueryRecord', ['RecordID' => $RecordId]);
		if($data){
			if($data['name'] == $data['zone_name']) $data['name'] = '@';
			return [
				'RecordId' => $data['RecordID'],
				'Domain' => $this->domain,
				'Name' => $data['Host'],
				'Type' => $data['Type'],
				'Value' => $data['Value'],
				'Line' => $data['Line'],
				'TTL' => $data['TTL'],
				'MX' => $data['Weight'],
				'Status' => $data['Enable'] ? '1' : '0',
				'Weight' => $data['Weight'],
				'Remark' => $data['Remark'],
				'UpdateTime' => $data['UpdatedAt'],
			];
		}
		return false;
	}

	//添加解析记录
	public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Remark = null){
		$params = ['ZID' => intval($this->domainid), 'Host' => $Name, 'Type' => $this->convertType($Type), 'Value' => $Value, 'Line'=>$Line, 'TTL' => intval($TTL), 'Remark' => $Remark];
		if($Type == 'MX')$param['Weight'] = intval($MX);
		$data = $this->send_reuqest('POST', 'CreateRecord', $params);
		return is_array($data) ? $data['RecordID'] : false;
	}

	//修改解析记录
	public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Remark = null){
		$params = ['RecordID' => $RecordId, 'Host' => $Name, 'Type' => $this->convertType($Type), 'Value' => $Value, 'Line'=>$Line, 'TTL' => intval($TTL), 'Remark' => $Remark];
		if($Type == 'MX')$param['Weight'] = intval($MX);
		$data = $this->send_reuqest('POST', 'UpdateRecord', $params);
		return is_array($data);
	}

	//修改解析记录备注
	public function updateDomainRecordRemark($RecordId, $Remark){
		return false;
	}

	//删除解析记录
	public function deleteDomainRecord($RecordId){
		$data = $this->send_reuqest('POST', 'DeleteRecord', ['RecordID' => $RecordId]);
		return $data;
	}
	
	//设置解析记录状态
	public function setDomainRecordStatus($RecordId, $Status){
		$params = ['RecordID' => $RecordId, 'Enable' => $Status == '1'];
		$data = $this->send_reuqest('POST', 'UpdateRecordStatus', $params);
		return is_array($data);
	}

	//获取解析记录操作日志
	public function getDomainRecordLog($PageNumber = 1, $PageSize = 20, $KeyWord = null, $StartDate = null, $endDate = null){
		return false;
	}

	//获取解析线路列表
	public function getRecordLine(){
		$domainInfo = $this->getDomainInfo();
		if(!$domainInfo) return false;
		$level = $this->getTradeInfo($domainInfo['TradeCode'])['level'];
		$data = $this->send_reuqest('GET', 'ListLines', []);
		if($data){
			$list = [];
			$list['default'] = ['name' => '默认', 'parent' => null];
			foreach($data['Lines'] as $row){
				if($row['Value'] == 'default') continue;
				if($row['Level'] > $level) continue;
				$list[$row['Value']] = ['name' => $row['Name'], 'parent' => isset($row['FatherValue']) ? $row['FatherValue'] : null];
			}

			$data = $this->send_reuqest('GET', 'ListCustomLines', []);
			if($data && $data['TotalCount'] > 0){
				$list['N.customer_lines'] = ['name' => '自定义线路', 'parent' => null];
				foreach($data['CustomerLines'] as $row){
					$list[$row['Line']] = ['name' => $row['NameCN'], 'parent' => 'N.customer_lines'];
				}
			}

			return $list;
		}
		return false;
	}

	//获取域名概览信息
	public function getDomainInfo(){
		if(!empty($this->domainInfo)) return $this->domainInfo;
		$query = ['ZID' => intval($this->domainid)];
		$data = $this->send_reuqest('GET', 'QueryZone', $query);
		if($data){
			$this->domainInfo = $data;
			return $data;
		}
		return false;
	}

	//获取域名最低TTL
	public function getMinTTL(){
		$domainInfo = $this->getDomainInfo();
		if($domainInfo){
			$ttl = $this->getTradeInfo($domainInfo['TradeCode'])['ttl'];
			return $ttl;
		}
		return false;
	}

	private function convertType($type){
		return $type;
	}

	private function getTradeInfo($trade_code){
		if(array_key_exists($trade_code, self::$trade_code_list)){
			$trade_code = $trade_code;
		}else{
			$trade_code = 'free_inner';
		}
		return self::$trade_code_list[$trade_code];
	}

	private function send_reuqest($method, $action, $params = []){
		if(!empty($params)){
			$params = array_filter($params, function($a){ return $a!==null;});
		}

		$query = [
			'Action' => $action,
			'Version' => $this->version,
		];

		$body = '';
		if($method == 'GET'){
			$query = array_merge($query, $params);
		}else{
			$body = !empty($params) ? json_encode($params) : '';
		}

		$time = time();
		$headers = [
			'Host' => $this->endpoint,
			'X-Date' => gmdate("Ymd\THis\Z", $time),
			//'X-Content-Sha256' => hash("sha256", $body),
		];
		if($body){
			$headers['Content-Type'] = 'application/json';
		}
		$path = '/';
		
		$authorization = $this->generateSign($method, $path, $query, $headers, $body, $time);
		$headers['Authorization'] = $authorization;

		$url = 'https://'.$this->endpoint.$path.'?'.http_build_query($query);
		$header = [];
		foreach($headers as $key => $value){
			$header[] = $key.': '.$value;
		}
		return $this->curl($method, $url, $body, $header);
	}

	private function generateSign($method, $path, $query, $headers, $body, $time){
		$algorithm = "HMAC-SHA256";

		// step 1: build canonical request string
		$httpRequestMethod = $method;
		$canonicalUri = $path;
		if(substr($canonicalUri, -1) != "/") $canonicalUri .= "/";
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
		$shortDate = substr($date, 0, 8);
		$credentialScope = $shortDate . '/' .$this->region . '/' . $this->service . '/request';
		$hashedCanonicalRequest = hash("sha256", $canonicalRequest);
		$stringToSign = $algorithm."\n"
			.$date."\n"
			.$credentialScope."\n"
			.$hashedCanonicalRequest;
		
		// step 3: sign string
		$kDate = hash_hmac("sha256", $shortDate, $this->SecretAccessKey, true);
		$kRegion = hash_hmac("sha256", $this->region, $kDate, true);
		$kService = hash_hmac("sha256", $this->service, $kRegion, true);
		$kSigning = hash_hmac("sha256", "request", $kService, true);
		$signature = hash_hmac("sha256", $stringToSign, $kSigning);

		// step 4: build authorization
		$credential = $this->AccessKeyId . '/' . $shortDate . '/' . $this->region . '/' . $this->service . '/request';
		$authorization = $algorithm . ' Credential=' . $credential . ", SignedHeaders=" . $signedHeaders . ", Signature=" . $signature;

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
		if(empty($parameters)) return '';
        ksort($parameters);
		$canonicalQueryString = '';
		foreach ($parameters as $key => $value) {
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
			$canonicalHeaders .= $key . ':' . $value . "\n";
			$signedHeaders .= $key . ';';
		}
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
		if ($errno) {
			$this->setError('Curl error: ' . curl_error($ch));
		}
		curl_close($ch);
		if ($errno) return false;

		$arr=json_decode($response,true);
		if($arr){
			if(isset($arr['ResponseMetadata']['Error']['MessageCN'])){
				$this->setError($arr['ResponseMetadata']['Error']['MessageCN']);
				return false;
			}elseif(isset($arr['ResponseMetadata']['Error']['Message'])){
				$this->setError($arr['ResponseMetadata']['Error']['Message']);
				return false;
			}elseif(isset($arr['Result'])){
				return $arr['Result'];
			}else{
				return true;
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