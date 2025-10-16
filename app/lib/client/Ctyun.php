<?php

namespace app\lib\client;

use Exception;

/**
 * 天翼云
 */
class Ctyun
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
        $date = date("Ymd\THis\Z", $time);
        $body = !empty($params) ? json_encode($params) : '';
        $headers = [
            'Host' => $this->endpoint,
            'Eop-date' => $date,
            'ctyun-eop-request-id' => getSid(),
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/json';
        }

        $authorization = $this->generateSign($query, $headers, $body, $date);
        $headers['Eop-Authorization'] = $authorization;

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

    private function generateSign($query, $headers, $body, $date)
    {
        // step 1: build canonical request string
        $canonicalQueryString = $this->getCanonicalQueryString($query);
        [$canonicalHeaders, $signedHeaders] = $this->getCanonicalHeaders($headers);
        $hashedRequestPayload = hash("sha256", $body);

        // step 2: build string to sign
        $stringToSign = $canonicalHeaders . "\n"
            . $canonicalQueryString . "\n"
            . $hashedRequestPayload;

        // step 3: sign string
        $ktime = hash_hmac("sha256", $date, $this->SecretAccessKey, true);
        $kAk = hash_hmac("sha256", $this->AccessKeyId, $ktime, true);
        $kdate = hash_hmac("sha256", substr($date, 0, 8), $kAk, true);
        $signature = hash_hmac("sha256", $stringToSign, $kdate, true);
        $signature = base64_encode($signature);

        // step 4: build authorization
        $authorization = $this->AccessKeyId . " Headers=" . $signedHeaders . " Signature=" . $signature;

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
        curl_close($ch);

        $arr = json_decode($response, true);
        if (isset($arr['statusCode']) && $arr['statusCode'] == 100000) {
            return isset($arr['returnObj']) ? $arr['returnObj'] : true;
        } elseif (isset($arr['errorMessage'])) {
            throw new Exception($arr['errorMessage']);
        } elseif (isset($arr['message'])) {
            throw new Exception($arr['message']);
        } else {
            throw new Exception('返回数据解析失败');
        }
    }
}
