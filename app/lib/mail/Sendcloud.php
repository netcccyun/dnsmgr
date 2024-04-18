<?php
namespace app\lib\mail;

class Sendcloud {
	private $apiUser;
	private $apiKey;

	function __construct($apiUser, $apiKey){
        $this->apiUser = $apiUser;
        $this->apiKey = $apiKey;
    }
	public function send($to, $sub, $msg, $from, $from_name){
		if(empty($this->apiUser)||empty($this->apiKey))return false;
		$url='http://api.sendcloud.net/apiv2/mail/send';
		$data=array(
			'apiUser' => $this->apiUser,
			'apiKey' => $this->apiKey,
			'from' => $from,
			'fromName' => $from_name,
			'to' => $to,
			'subject' => $sub,
			'html' => $msg);
		$ch=curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		$json=curl_exec($ch);
		curl_close($ch);
		$arr=json_decode($json,true);
		if($arr['statusCode']==200){
			return true;
		}else{
			return implode("\n",$arr['message']);
		}
	}
}
