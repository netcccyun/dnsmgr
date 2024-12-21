<?php

namespace app\lib\client;

use Exception;

/**
 * 华为云
 */
class HuaweiCloud
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint;

    public function __construct($AccessKeyId, $SecretAccessKey, $endpoint)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->SecretAccessKey = $SecretAccessKey;
        $this->endpoint = $endpoint;
    }

    /**
     * @param string $method 请求方法
     * @param string $path 请求路径
     * @param array|null $query 请求参数
     * @param array|null $params 请求体
     * @return array
     * @throws Exception
     */
    public function request($method, $path, $query = null, $params = null)
    {
        if (!empty($query)) {
            $query = array_filter($query, function ($a) { return $a !== null;});
        }
        if (!empty($params)) {
            $params = array_filter($params, function ($a) { return $a !== null;});
        }

        $time = time();
        $date = gmdate("Ymd\THis\Z", $time);
        $body = !empty($params) ? json_encode($params) : '';
        $headers = [
            'Host' => $this->endpoint,
            'X-Sdk-Date' => $date,
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/json';
        }

        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $time);
        $headers['Authorization'] = $authorization;

        $url = 'https://' . $this->endpoint . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $this->curl($method, $url, $body, $header);
    }

    private function generateSign($method, $path, $query, $headers, $body, $time)
    {
        $algorithm = "SDK-HMAC-SHA256";

        // step 1: build canonical request string
        $httpRequestMethod = $method;
        $canonicalUri = $path;
        if (substr($canonicalUri, -1) != "/") $canonicalUri .= "/";
        $canonicalQueryString = $this->getCanonicalQueryString($query);
        [$canonicalHeaders, $signedHeaders] = $this->getCanonicalHeaders($headers);
        $hashedRequestPayload = hash("sha256", $body);
        $canonicalRequest = $httpRequestMethod . "\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $hashedRequestPayload;

        // step 2: build string to sign
        $date = gmdate("Ymd\THis\Z", $time);
        $hashedCanonicalRequest = hash("sha256", $canonicalRequest);
        $stringToSign = $algorithm . "\n"
            . $date . "\n"
            . $hashedCanonicalRequest;

        // step 3: sign string
        $signature = hash_hmac("sha256", $stringToSign, $this->SecretAccessKey);

        // step 4: build authorization
        $authorization = $algorithm . ' Access=' . $this->AccessKeyId . ", SignedHeaders=" . $signedHeaders . ", Signature=" . $signature;

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
            $canonicalQueryString .= '&' . $this->escape($key) . '=' . $this->escape($value);
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
        curl_close($ch);

        $arr = json_decode($response, true);
        if ($arr) {
            if (isset($arr['error_msg'])) {
                throw new Exception($arr['error_msg']);
            } elseif (isset($arr['message'])) {
                throw new Exception($arr['message']);
            } elseif (isset($arr['error']['error_msg'])) {
                throw new Exception($arr['error']['error_msg']);
            } else {
                return $arr;
            }
        } else {
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 200 && $httpCode < 300) {
                return null;
            } else {
                throw new Exception('返回数据解析失败');
            }
        }
    }
}
