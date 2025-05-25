<?php

namespace app\lib\client;

use Exception;

/**
 * 腾讯云
 */
class TencentCloud
{
    private $SecretId;
    private $SecretKey;
    private $endpoint;
    private $service;
    private $version;
    private $region;
    private $proxy = false;

    public function __construct($SecretId, $SecretKey, $endpoint, $service, $version, $region = null, $proxy = false)
    {
        $this->SecretId = $SecretId;
        $this->SecretKey = $SecretKey;
        $this->endpoint = $endpoint;
        $this->service = $service;
        $this->version = $version;
        $this->region = $region;
        $this->proxy = $proxy;
    }

    /**
     * @param string $action 方法名称
     * @param array $param 请求参数
     * @return array
     * @throws Exception
     */
    public function request($action, $param)
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
        if($this->region) {
            $header[] = 'X-TC-Region: '.$this->region;
        }
        $res = $this->curl_post($payload, $header);
        return $res;
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
        if ($this->proxy) {
            curl_set_proxy($ch);
        }
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
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception('Curl error: ' . $errmsg);
        }
        curl_close($ch);

        $arr = json_decode($response, true);
        if ($arr) {
            if (isset($arr['Response']['Error'])) {
                throw new Exception($arr['Response']['Error']['Message']);
            } else {
                return $arr['Response'];
            }
        } else {
            throw new Exception('返回数据解析失败');
        }
    }
}