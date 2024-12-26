<?php

namespace app\lib\client;

use Exception;

/**
 * 百度云
 */
class BaiduCloud
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint;
    private $proxy = false;

    public function __construct($AccessKeyId, $SecretAccessKey, $endpoint, $proxy = false)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->SecretAccessKey = $SecretAccessKey;
        $this->endpoint = $endpoint;
        $this->proxy = $proxy;
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
        $date = gmdate("Y-m-d\TH:i:s\Z", $time);
        $body = !empty($params) ? json_encode($params) : '';
        $headers = [
            'Host' => $this->endpoint,
            'x-bce-date' => $date,
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/json';
        }

        $authorization = $this->generateSign($method, $path, $query, $headers, $time);
        $headers['Authorization'] = $authorization;

        $url = 'https://'.$this->endpoint.$path;
        if (!empty($query)) {
            $url .= '?'.http_build_query($query);
        }
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key.': '.$value;
        }
        return $this->curl($method, $url, $body, $header);
    }

    private function generateSign($method, $path, $query, $headers, $time)
    {
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
        if (empty($path)) return '/';
        $uri = str_replace('%2F', '/', $this->escape($path));
        if (substr($uri, 0, 1) !== '/') $uri = '/' . $uri;
        return $uri;
    }

    private function getCanonicalQueryString($parameters)
    {
        if (empty($parameters)) return '';
        ksort($parameters);
        $canonicalQueryString = '';
        foreach ($parameters as $key => $value) {
            if ($key == 'authorization') continue;
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
            $canonicalHeaders .= $this->escape($key) . ':' . $this->escape($value) . "\n";
            $signedHeaders .= $key . ';';
        }
        $canonicalHeaders = substr($canonicalHeaders, 0, -1);
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($errno) {
            curl_close($ch);
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        curl_close($ch);

        if (empty($response) && $httpCode == 200) {
            return true;
        }
        $arr = json_decode($response, true);
        if ($arr) {
            if (isset($arr['code']) && isset($arr['message'])) {
                throw new Exception($arr['message']);
            } else {
                return $arr;
            }
        } else {
            throw new Exception('返回数据解析失败');
        }
    }
}