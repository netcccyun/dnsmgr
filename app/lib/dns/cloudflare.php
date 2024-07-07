<?php
namespace app\lib\dns;

use app\lib\DnsInterface;

class cloudflare implements DnsInterface {
	private $Email;
	private $ApiKey;
	private $baseUrl = 'https://api.cloudflare.com/client/v4';
	private $error;
	private $domain;
	private $domainid;

	function __construct($config){
		$this->Email = $config['ak'];
		$this->ApiKey = $config['sk'];
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
		$param = ['page' => $PageNumber, 'per_page' => $PageSize, 'name' => $KeyWord];
		$data = $this->send_reuqest('GET', '/zones', $param);
		if($data){
			$list = [];
			foreach($data['result'] as $row){
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
	public function getDomainRecords($PageNumber=1, $PageSize=20, $KeyWord = null, $SubDomain = null, $Value = null, $Type = null, $Line = null, $Status = null){
		if(!isNullOrEmpty($SubDomain)){
			if($SubDomain == '@')$SubDomain=$this->domain;
			else $SubDomain .= '.'.$this->domain;
		}
		if(!isNullOrEmpty($Value)) $KeyWord = $Value;
		$param = ['name' => $SubDomain, 'type' => $Type, 'search' => $KeyWord, 'page' => $PageNumber, 'per_page' => $PageSize];
		if(!isNullOrEmpty($Line)){
			$param['proxied'] = $Line == '1' ? 'true' : 'false';
		}
		$data = $this->send_reuqest('GET', '/zones/'.$this->domainid.'/dns_records', $param);
		if($data){
			$list = [];
			foreach($data['result'] as $row){
				$name = $row['zone_name'] == $row['name'] ? '@' : str_replace('.'.$row['zone_name'], '', $row['name']);
				$list[] = [
					'RecordId' => $row['id'],
					'Domain' => $row['zone_name'],
					'Name' => $name,
					'Type' => $row['type'],
					'Value' => $row['content'],
					'Line' => $row['proxied'] ? '1' : '0',
					'TTL' => $row['ttl'],
					'MX' => isset($row['priority']) ? $row['priority'] : null,
					'Status' => $row['locked'] ? '0' : '1',
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
	public function getSubDomainRecords($SubDomain, $PageNumber=1, $PageSize=20, $Type = null, $Line = null){
		return $this->getDomainRecords($PageNumber, $PageSize, null, $SubDomain, null, $Type, $Line);
	}

	//获取解析记录详细信息
	public function getDomainRecordInfo($RecordId){
		$data = $this->send_reuqest('GET', '/zones/'.$this->domainid.'/dns_records/'.$RecordId);
		if($data){
			$name = $data['result']['zone_name'] == $data['result']['name'] ? '@' : str_replace('.'.$data['result']['zone_name'], '', $data['result']['name']);
			return [
				'RecordId' => $data['result']['id'],
				'Domain' => $data['result']['zone_name'],
				'Name' => str_replace('.'.$data['result']['zone_name'], '', $data['result']['name']),
				'Type' => $data['result']['type'],
				'Value' => $data['result']['content'],
				'Line' => $data['result']['proxied'] ? '1' : '0',
				'TTL' => $data['result']['ttl'],
				'MX' => isset($data['result']['priority']) ? $data['result']['priority'] : null,
				'Status' => $data['result']['locked'] ? '0' : '1',
				'Weight' => null,
				'Remark' => $data['result']['comment'],
				'UpdateTime' => $data['result']['modified_on'],
			];
		}
		return false;
	}

	//添加解析记录
	public function addDomainRecord($Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Remark = null){
		$param = ['name' => $Name, 'type' => $this->convertType($Type), 'content' => $Value, 'proxied' => $Line=='1', 'ttl' => intval($TTL), 'comment' => $Remark];
		if($Type == 'MX')$param['priority'] = intval($MX);
		$data = $this->send_reuqest('POST', '/zones/'.$this->domainid.'/dns_records', $param);
		return is_array($data) ? $data['result']['id'] : false;
	}

	//修改解析记录
	public function updateDomainRecord($RecordId, $Name, $Type, $Value, $Line = '0', $TTL = 600, $MX = 1, $Remark = null){
		$param = ['name' => $Name, 'type' => $this->convertType($Type), 'content' => $Value, 'proxied' => $Line=='1', 'ttl' => intval($TTL), 'comment' => $Remark];
		if($Type == 'MX')$param['priority'] = intval($MX);
		$data = $this->send_reuqest('PATCH', '/zones/'.$this->domainid.'/dns_records/'.$RecordId, $param);
		return is_array($data);
	}

	//修改解析记录备注
	public function updateDomainRecordRemark($RecordId, $Remark){
		return false;
	}

	//删除解析记录
	public function deleteDomainRecord($RecordId){
		$data = $this->send_reuqest('DELETE', '/zones/'.$this->domainid.'/dns_records/'.$RecordId);
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
		return ['0'=>['name'=>'仅DNS', 'parent'=>null], '1'=>['name'=>'已代理', 'parent'=>null]];
	}

	//获取域名信息
	public function getDomainInfo(){
		$data = $this->send_reuqest('GET', '/zones/'.$this->domainid);
		if($data){
			return $data['result'];
		}
		return false;
	}

	//获取域名最低TTL
	public function getMinTTL(){
		return false;
	}

	private function convertType($type){
		$convert_dict = ['REDIRECT_URL'=>'URI', 'FORWARD_URL'=>'URI'];
		if(array_key_exists($type, $convert_dict)){
			return $convert_dict[$type];
		}
		return $type;
	}

	private function send_reuqest($method, $path, $params = null){
		$url = $this->baseUrl . $path;
		
		if(preg_match('/^[0-9a-z]+$/i',$this->ApiKey)){
			$headers = [
				'X-Auth-Email: '.$this->Email,
				'X-Auth-Key: '.$this->ApiKey,
			];
		}else{
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
		if ($errno) return false;

		$arr=json_decode($response,true);
		if($arr){
			if($arr['success']){
				return $arr;
			}else{
				$this->setError(isset($arr['errors'][0])?$arr['errors'][0]['message']:'未知错误');
				return false;
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