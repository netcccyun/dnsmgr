<?php

namespace app\lib\client;

use Exception;

/**
 * 金山云
 */
class Ksyun
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint;
    private $service;
    private $region;
    private $proxy = false;

    public function __construct($AccessKeyId, $SecretAccessKey, $endpoint, $service, $region, $proxy = false)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->SecretAccessKey = $SecretAccessKey;
        $this->endpoint = $endpoint;
        $this->service = $service;
        $this->region = $region;
        $this->proxy = $proxy;
    }

    /**
     * @param string $method 请求方法
     * @param string $action 方法名称
     * @param array $params 请求参数
     * @return array
     * @throws Exception
     */
    public function request($method, $action, $version, $path = '/', $params = [])
    {
        if (!empty($params)) {
            $params = array_filter($params, function ($a) {
                return $a !== null;
            });
        }

        $body = '';
        $query = [];
        if ($method == 'GET') {
            $query = $params;
        } else {
            $body = !empty($params) ? json_encode($params) : '';
        }

        $time = time();
        $headers = [
            'Host' => $this->endpoint,
            'X-Amz-Date' => gmdate("Ymd\THis\Z", $time),
            'X-Version' => $version,
            'X-Action' => $action,
        ];

        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $time);
        $headers['Authorization'] = $authorization;
        $headers['Accept'] = 'application/json';
        if ($body) {
            $headers['Content-Type'] = 'application/json';
        }

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
        $algorithm = "AWS4-HMAC-SHA256";

        // step 1: build canonical request string
        $httpRequestMethod = $method;
        $canonicalUri = $this->getCanonicalURI($path);
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
        $shortDate = substr($date, 0, 8);
        $credentialScope = $shortDate . '/' . $this->region . '/' . $this->service . '/aws4_request';
        $hashedCanonicalRequest = hash("sha256", $canonicalRequest);
        $stringToSign = $algorithm . "\n"
            . $date . "\n"
            . $credentialScope . "\n"
            . $hashedCanonicalRequest;

        // step 3: sign string
        $kDate = hash_hmac("sha256", $shortDate, 'AWS4' . $this->SecretAccessKey, true);
        $kRegion = hash_hmac("sha256", $this->region, $kDate, true);
        $kService = hash_hmac("sha256", $this->service, $kRegion, true);
        $kSigning = hash_hmac("sha256", "aws4_request", $kService, true);
        $signature = hash_hmac("sha256", $stringToSign, $kSigning);

        // step 4: build authorization
        $credential = $this->AccessKeyId . '/' . $credentialScope;
        $authorization = $algorithm . ' Credential=' . $credential . ", SignedHeaders=" . $signedHeaders . ", Signature=" . $signature;

        return $authorization;
    }

    private function escape($str)
    {
        $search = ['+', '*', '%7E'];
        $replace = ['%20', '%2A', '~'];
        return str_replace($search, $replace, urlencode($str));
    }

    private function getCanonicalURI($path)
    {
        if (empty($path)) return '/';
        $pattens = explode('/', $path);
        $pattens = array_map(function ($item) {
            return $this->escape($item);
        }, $pattens);
        $canonicalURI = implode('/', $pattens);
        return $canonicalURI;
    }

    private function getCanonicalQueryString($parameters)
    {
        if (empty($parameters)) return '';
        ksort($parameters);
        $canonicalQueryString = '';
        foreach ($parameters as $key => $value) {
            if (!is_array($value)) {
                $canonicalQueryString .= '&' . $this->escape($key) . '=' . $this->escape($value);
            } else {
                sort($value);
                foreach ($value as $v) {
                    $canonicalQueryString .= '&' . $this->escape($key) . '=' . $this->escape($v);
                }
            }
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
            return $arr;
        } else {
            if (isset($arr['Error']['Message'])) {
                throw new Exception($arr['Error']['Message']);
            } else {
                throw new Exception('返回数据解析失败(http_code=' . $httpCode . ')');
            }
        }
    }
}
