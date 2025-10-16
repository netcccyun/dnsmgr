<?php

namespace app\lib\client;

use Exception;

/**
 * 火山引擎
 */
class Volcengine
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint = "open.volcengineapi.com";
    private $service;
    private $version;
    private $region;
    private $proxy = false;

    public function __construct($AccessKeyId, $SecretAccessKey, $endpoint, $service, $version, $region, $proxy = false)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->SecretAccessKey = $SecretAccessKey;
        $this->endpoint = $endpoint;
        $this->service = $service;
        $this->version = $version;
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
    public function request($method, $action, $params = [], $querys = [])
    {
        if (!empty($params)) {
            $params = array_filter($params, function ($a) {
                return $a !== null;
            });
        }

        $query = [
            'Action' => $action,
            'Version' => $this->version,
        ];

        $body = '';
        if ($method == 'GET') {
            $query = array_merge($query, $params);
        } else {
            $body = !empty($params) ? json_encode($params) : '';
            if (!empty($querys)) {
                $query = array_merge($query, $querys);
            }
        }

        $time = time();
        $headers = [
            'Host' => $this->endpoint,
            'X-Date' => gmdate("Ymd\THis\Z", $time),
            //'X-Content-Sha256' => hash("sha256", $body),
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/json';
        }
        $path = '/';

        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $time);
        $headers['Authorization'] = $authorization;

        $url = 'https://' . $this->endpoint . $path . '?' . http_build_query($query);
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $this->curl($method, $url, $body, $header);
    }

    /**
     * @param string $method 请求方法
     * @param string $action 方法名称
     * @param array $params 请求参数
     * @return array
     * @throws Exception
     */
    public function tos_request($method, $params = [], $query = [])
    {
        if (!empty($params)) {
            $params = array_filter($params, function ($a) {
                return $a !== null;
            });
        }

        $body = '';
        if ($method != 'GET') {
            $body = !empty($params) ? json_encode($params) : '';
        }

        $time = time();
        $headers = [
            'Host' => $this->endpoint,
            'X-Tos-Date' => gmdate("Ymd\THis\Z", $time),
            'X-Tos-Content-Sha256' => hash("sha256", $body),
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/json';
        }
        $path = '/';

        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $time);
        $headers['Authorization'] = $authorization;

        $url = 'https://' . $this->endpoint . $path . '?' . http_build_query($query);
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $this->curl($method, $url, $body, $header);
    }

    private function generateSign($method, $path, $query, $headers, $body, $time)
    {
        $algorithm = $this->service == 'tos' ? "TOS4-HMAC-SHA256" : "HMAC-SHA256";

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
        $shortDate = substr($date, 0, 8);
        $credentialScope = $shortDate . '/' . $this->region . '/' . $this->service . '/request';
        $hashedCanonicalRequest = hash("sha256", $canonicalRequest);
        $stringToSign = $algorithm . "\n"
            . $date . "\n"
            . $credentialScope . "\n"
            . $hashedCanonicalRequest;

        // step 3: sign string
        $kDate = hash_hmac("sha256", $shortDate, $this->SecretAccessKey, true);
        $kRegion = hash_hmac("sha256", $this->region, $kDate, true);
        $kService = hash_hmac("sha256", $this->service, $kRegion, true);
        $kSigning = hash_hmac("sha256", "request", $kService, true);
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
            if (isset($arr['ResponseMetadata']['Error']['MessageCN'])) {
                throw new Exception($arr['ResponseMetadata']['Error']['MessageCN']);
            } elseif (isset($arr['ResponseMetadata']['Error']['Message'])) {
                throw new Exception($arr['ResponseMetadata']['Error']['Message']);
            } elseif (isset($arr['Result'])) {
                return $arr['Result'];
            }
            return true;
        } else {
            if (isset($arr['ResponseMetadata']['Error']['MessageCN'])) {
                throw new Exception($arr['ResponseMetadata']['Error']['MessageCN']);
            } elseif (isset($arr['ResponseMetadata']['Error']['Message'])) {
                throw new Exception($arr['ResponseMetadata']['Error']['Message']);
            } elseif (isset($arr['Message'])) {
                throw new Exception($arr['Message']);
            } elseif (isset($arr['message'])) {
                throw new Exception($arr['message']);
            } else {
                throw new Exception('返回数据解析失败(http_code=' . $httpCode . ')');
            }
        }
    }
}
