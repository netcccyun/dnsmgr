<?php

namespace app\lib\client;

use Exception;

/**
 * AWS
 */
class AWS
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint;
    private $service;
    private $version;
    private $region;
    private $etag;

    public function __construct($AccessKeyId, $SecretAccessKey, $endpoint, $service, $version, $region)
    {
        $this->AccessKeyId = $AccessKeyId;
        $this->SecretAccessKey = $SecretAccessKey;
        $this->endpoint = $endpoint;
        $this->service = $service;
        $this->version = $version;
        $this->region = $region;
    }

    /**
     * @param string $method 请求方法
     * @param string $action 方法名称
     * @param array $params 请求参数
     * @return array
     * @throws Exception
     */
    public function request($method, $action, $params = [])
    {
        if (!empty($params)) {
            $params = array_filter($params, function ($a) {
                return $a !== null;
            });
        }

        $body = '';
        $query = [];
        if ($method == 'GET' || $method == 'DELETE') {
            $query = $params;
        } else {
            $body = !empty($params) ? json_encode($params) : '';
        }

        $time = time();
        $date = gmdate("Ymd\THis\Z", $time);
        $headers = [
            'Host' => $this->endpoint,
            'X-Amz-Target' => $action,
            'X-Amz-Date' => $date,
            //'X-Amz-Content-Sha256' => hash("sha256", $body),
        ];
        if ($body) {
            $headers['Content-Type'] = 'application/x-amz-json-1.1';
        }
        $path = '/';

        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $date);
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

    /**
     * @param string $method 请求方法
     * @param string $action 方法名称
     * @param array $params 请求参数
     * @return array
     * @throws Exception
     */
    public function requestXml($method, $action, $params = [])
    {
        if (!empty($params)) {
            $params = array_filter($params, function ($a) {
                return $a !== null;
            });
        }

        $body = '';
        $query = [
            'Action' => $action,
            'Version' => $this->version,
        ];
        if ($method == 'GET' || $method == 'DELETE') {
            $query = array_merge($query, $params);
        } else {
            $body = !empty($params) ? http_build_query($params) : '';
        }

        $time = time();
        $date = gmdate("Ymd\THis\Z", $time);
        $headers = [
            'Host' => $this->endpoint,
            'X-Amz-Date' => $date,
        ];

        $path = '/';
        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $date);
        $headers['Authorization'] = $authorization;

        $url = 'https://' . $this->endpoint . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $this->curl($method, $url, $body, $header, true);
    }

    /**
     * @param string $method 请求方法
     * @param string $path 请求路径
     * @param array $params 请求参数
     * @param \SimpleXMLElement $xml 请求XML
     * @return array
     * @throws Exception
     */
    public function requestXmlN($method, $path, $params = [], $xml = null, $etag = false)
    {
        if (!empty($params)) {
            $params = array_filter($params, function ($a) {
                return $a !== null;
            });
        }

        $path = '/' . $this->version . $path;
        if ($method == 'GET' || $method == 'DELETE') {
            $query = $params;
        } else {
            $body = !empty($params) ? $this->array2xml($params, $xml) : '';
        }

        $time = time();
        $date = gmdate("Ymd\THis\Z", $time);
        $headers = [
            'Host' => $this->endpoint,
            'X-Amz-Date' => $date,
            //'X-Amz-Content-Sha256' => hash("sha256", $body),
        ];
        if ($this->etag) {
            $headers['If-Match'] = $this->etag;
        }

        $authorization = $this->generateSign($method, $path, $query, $headers, $body, $date);
        $headers['Authorization'] = $authorization;

        $url = 'https://' . $this->endpoint . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        $header = [];
        foreach ($headers as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $this->curl($method, $url, $body, $header, true, $etag);
    }

    private function generateSign($method, $path, $query, $headers, $body, $date)
    {
        $algorithm = "AWS4-HMAC-SHA256";

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

    private function curl($method, $url, $body, $header, $xml = false, $etag = false)
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
        if ($etag) {
            curl_setopt($ch, CURLOPT_HEADER, true);
        }
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno) {
            curl_close($ch);
            throw new Exception('Curl error: ' . curl_error($ch));
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($etag) {
            if (preg_match('/ETag: ([^\r\n]+)/', $response, $matches)) {
                $this->etag = trim($matches[1]);
            }
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $response = substr($response, $headerSize);
        }
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            if (empty($response)) return true;
            return $xml ? $this->xml2array($response) : json_decode($response, true);
        }
        if ($xml) {
            $arr = $this->xml2array($response);
            if (isset($arr['Error']['Message'])) {
                throw new Exception($arr['Error']['Message']);
            } else {
                throw new Exception('HTTP Code: ' . $httpCode);
            }
        } else {
            $arr = json_decode($response, true);
            if (isset($arr['message'])) {
                throw new Exception($arr['message']);
            } else {
                throw new Exception('HTTP Code: ' . $httpCode);
            }
        }
    }

    private function xml2array($xml)
    {
        if (!$xml) {
            return false;
        }
        LIBXML_VERSION < 20900 && libxml_disable_entity_loader(true);
        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA), JSON_UNESCAPED_UNICODE), true);
    }

    private function array2xml($array, $xml = null)
    {
        if ($xml === null) {
            $xml = new \SimpleXMLElement('<root/>');
        }

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $subNode = $xml->addChild($key);
                $this->array2xml($value, $subNode);
            } else {
                $xml->addChild($key, $value);
            }
        }

        return $xml->asXML();
    }
}
