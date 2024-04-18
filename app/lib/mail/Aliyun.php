<?php
namespace app\lib\mail;

class Aliyun
{
	private $AccessKeyId;
	private $AccessKeySecret;

	function __construct($AccessKeyId, $AccessKeySecret)
	{
		$this->AccessKeyId = $AccessKeyId;
		$this->AccessKeySecret = $AccessKeySecret;
	}
	private function aliyunSignature($parameters, $accessKeySecret, $method)
	{
		ksort($parameters);
		$canonicalizedQueryString = '';
		foreach ($parameters as $key => $value) {
			if($value === null) continue;
			$canonicalizedQueryString .= '&' . $this->percentEncode($key) . '=' . $this->percentEncode($value);
		}
		$stringToSign = $method . '&%2F&' . $this->percentencode(substr($canonicalizedQueryString, 1));
		$signature = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret . "&", true));

		return $signature;
	}
	private function percentEncode($str)
	{
		$search = ['+', '*', '%7E'];
		$replace = ['%20', '%2A', '~'];
		return str_replace($search, $replace, urlencode($str));
	}
	public function send($to, $sub, $msg, $from, $from_name)
	{
		if (empty($this->AccessKeyId) || empty($this->AccessKeySecret)) return false;
		$url = 'https://dm.aliyuncs.com/';
		$data = array(
			'Action' => 'SingleSendMail',
			'AccountName' => $from,
			'ReplyToAddress' => 'false',
			'AddressType' => 1,
			'ToAddress' => $to,
			'FromAlias' => $from_name,
			'Subject' => $sub,
			'HtmlBody' => $msg,
			'Format' => 'JSON',
			'Version' => '2015-11-23',
			'AccessKeyId' => $this->AccessKeyId,
			'SignatureMethod' => 'HMAC-SHA1',
			'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'SignatureVersion' => '1.0',
			'SignatureNonce' => random(8)
		);
		$data['Signature'] = $this->aliyunSignature($data, $this->AccessKeySecret, 'POST');
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
		$json = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$arr = json_decode($json, true);
		if ($httpCode == 200) {
			return true;
		} else {
			return $arr['Message'];
		}
	}
}