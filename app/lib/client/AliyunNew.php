<?php

namespace app\lib\client;

use Exception;

/**
 * 阿里云V3
 */
class AliyunNew
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
     * @param string $method 请求方法
     * @param string $action 操作名称
     * @param array|null $params 请求参数
     * @return array
     * @throws Exception
     */
    public function request($method, $action, $path = '/', $params = null)
    {
        if (!empty($params)) {
            $params = array_filter($params, function ($a) { return $a !== null;});
        }

        if($method == 'GET' || $method == 'DELETE'){
            $query = $params;
            $body = '';
        }else{
            $query = [];
            $body = !empty($params) ? json_encode($params) : '';
        }
        $headers = [
            'x-acs-action' => $action,
            'x-acs-version' => $this->Version,
            'x-acs-signature-nonce' => md5(uniqid(mt_rand(), true) . microtime()),
            'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'),
            'x-acs-content-sha256' => hash("sha256", $body),
            'Host' => $this->Endpoint,
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        $authorization = $this->generateSign($method, $path, $query, $headers, $body);
        $headers['Authorization'] = $authorization;

        $url = 'https://'.$this->Endpoint.$path;
        if (!empty($query)) {
            $url .= '?'.http_build_query($query);
        }
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key.': '.$value;
        }
        return $this->curl($method, $url, $body, $header);
    }

    private function generateSign($method, $path, $query, $headers, $body)
    {
        $algorithm = "ACS3-HMAC-SHA256";

        // step 1: build canonical request string
        $httpRequestMethod = $method;
        $canonicalUri = $path;
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
        $hashedCanonicalRequest = hash("sha256", $canonicalRequest);
        $stringToSign = $algorithm."\n"
            .$hashedCanonicalRequest;

        // step 3: sign string
        $signature = hash_hmac("sha256", $stringToSign, $this->AccessKeySecret);

        // step 4: build authorization
        $authorization = $algorithm . ' Credential=' . $this->AccessKeyId . ',SignedHeaders=' . $signedHeaders . ',Signature=' . $signature;

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
        if (empty($parameters)) return '';
        ksort($parameters);
        $canonicalQueryString = '';
        foreach ($parameters as $key => $value) {
            $canonicalQueryString .= '&' . $this->escape($key). '=' . $this->escape($value);
        }
        return substr($canonicalQueryString, 1);
    }

    private function getCanonicalHeaders($oldheaders)
    {
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

    private function curl($method, $url, $body, $header)
    {
        $ch = curl_init($url);
        if ($this->proxy) {
            curl_set_proxy($ch);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno) {
            curl_close($ch);
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $arr = json_decode($response, true);
        if ($httpCode == 200) {
            return $arr;
        } elseif ($arr) {
            if(strpos($arr['Message'], '.') > 0) $arr['Message'] = substr($arr['Message'], 0, strpos($arr['Message'], '.')+1);
            throw new Exception($arr['Message']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }
}