<?php

namespace app\lib\client;

use Exception;

/**
 * 京东云
 */
class Jdcloud
{
    private static $algorithm = 'JDCLOUD2-HMAC-SHA256';
    private $AccessKeyId;
    private $AccessKeySecret;
    private $endpoint;
    private $service;
    private $region;
    private $proxy = false;

    public function __construct($AccessKeyId, $AccessKeySecret, $endpoint, $service, $region, $proxy = false)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->AccessKeySecret = $AccessKeySecret;
        $this->endpoint = $endpoint;
        $this->service = $service;
        $this->region = $region;
        $this->proxy = $proxy;
    }

    /**
     * @param string $method 请求方法
     * @param string $path 请求路径
     * @param array $params 请求参数
     * @return array
     * @throws Exception
     */
    public function request($method, $path, $params = [])
    {
        if (!empty($params)) {
            $params = array_filter($params, function ($a) {
                return $a !== null;
            });
        }

        if ($method == 'GET' || $method == 'DELETE') {
            $query = $params;
            $body = '';
        } else {
            $query = [];
            $body = !empty($params) ? json_encode($params) : '';
        }

        $date = gmdate("Ymd\THis\Z");
        $headers = [
            'Host' => $this->endpoint,
            'x-jdcloud-algorithm' => self::$algorithm,
            'x-jdcloud-date' => $date,
            'x-jdcloud-nonce' => uniqid('php', true),
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/json';
        }

        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $date);
        $headers['authorization'] = $authorization;

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

    private function generateSign($method, $path, $query, $headers, $body, $date)
    {
        // step 1: build canonical request string
        $httpRequestMethod = $method;
        $canonicalUri = $path;
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
        $shortDate = substr($date, 0, 8);
        $credentialScope = $shortDate . '/' . $this->region . '/' . $this->service . '/jdcloud2_request';
        $hashedCanonicalRequest = hash("sha256", $canonicalRequest);
        $stringToSign = self::$algorithm . "\n"
            . $date . "\n"
            . $credentialScope . "\n"
            . $hashedCanonicalRequest;

        // step 3: sign string
        $kDate = hash_hmac("sha256", $shortDate, 'JDCLOUD2' . $this->AccessKeySecret, true);
        $kRegion = hash_hmac("sha256", $this->region, $kDate, true);
        $kService = hash_hmac("sha256", $this->service, $kRegion, true);
        $kSigning = hash_hmac("sha256", "jdcloud2_request", $kService, true);
        $signature = hash_hmac("sha256", $stringToSign, $kSigning);

        // step 4: build authorization
        $credential = $this->AccessKeyId . '/' . $credentialScope;
        $authorization = self::$algorithm . ' Credential=' . $credential . ", SignedHeaders=" . $signedHeaders . ", Signature=" . $signature;

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
            $errmsg = curl_error($ch);
            curl_close($ch);
            throw new Exception('Curl error: ' . $errmsg);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $arr = json_decode($response, true);
        if ($httpCode == 200) {
            if (isset($arr['result'])) {
                return $arr['result'];
            }
            return $arr;
        } else {
            if (isset($arr['error']['message'])) {
                throw new Exception($arr['error']['message']);
            } else {
                throw new Exception('返回数据解析失败(http_code=' . $httpCode . ')');
            }
        }
    }
}
