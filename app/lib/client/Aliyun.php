<?php

namespace app\lib\client;

use Exception;

/**
 * 阿里云
 */
class Aliyun
{
    private $AccessKeyId;
    private $AccessKeySecret;
    private $Endpoint;
    private $Version;
    private $proxy = false;

    public function __construct($AccessKeyId, $AccessKeySecret, $Endpoint, $Version, $proxy = false)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->AccessKeySecret = $AccessKeySecret;
        $this->Endpoint = $Endpoint;
        $this->Version = $Version;
        $this->proxy = $proxy;
    }

    /**
     * @param array $param 请求参数
     * @return bool|array
     * @throws Exception
     */
    public function request($param, $method = 'POST')
    {
        $url = 'https://' . $this->Endpoint . '/';
        $data = array(
            'Format' => 'JSON',
            'Version' => $this->Version,
            'AccessKeyId' => $this->AccessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => random(8)
        );
        $data = array_merge($data, $param);
        $data['Signature'] = $this->aliyunSignature($data, $this->AccessKeySecret, $method);
        if ($method == 'GET') {
            $url .= '?' . http_build_query($data);
        }
        $ch = curl_init($url);
        if ($this->proxy) {
            curl_set_proxy($ch);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno) {
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception('Curl error: ' . $errmsg);
        }
        curl_close($ch);

        $arr = json_decode($response, true);
        if ($httpCode == 200) {
            return $arr;
        } elseif ($arr) {
            throw new Exception($arr['Message']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }

    private function aliyunSignature($parameters, $accessKeySecret, $method)
    {
        ksort($parameters);
        $canonicalizedQueryString = '';
        foreach ($parameters as $key => $value) {
            if ($value === null) continue;
            $canonicalizedQueryString .= '&' . $this->percentEncode($key) . '=' . $this->percentEncode($value);
        }
        $stringToSign = $method . '&%2F&' . $this->percentEncode(substr($canonicalizedQueryString, 1));
        $signature = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret . "&", true));

        return $signature;
    }

    private function percentEncode($str)
    {
        $search = ['+', '*', '%7E'];
        $replace = ['%20', '%2A', '~'];
        return str_replace($search, $replace, urlencode($str));
    }
}
