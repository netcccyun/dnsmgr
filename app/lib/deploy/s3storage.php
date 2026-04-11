<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class s3storage implements DeployInterface
{
    private $logger;
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint;
    private $region;
    private $proxy;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->SecretAccessKey = $config['SecretAccessKey'];
        $this->endpoint = rtrim($config['endpoint'], '/');
        $this->region = !empty($config['region']) ? $config['region'] : 'us-east-1';
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function check()
    {
        if (empty($this->AccessKeyId) || empty($this->SecretAccessKey) || empty($this->endpoint)) {
            throw new Exception('必填参数不能为空');
        }

        $this->s3Request('GET', '/', '', null);
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $bucket = $config['bucket'];
        if (empty($bucket)) throw new Exception('存储桶名称不能为空');

        $certPath = trim($config['cert_path'], '/');
        $keyPath = trim($config['key_path'], '/');
        if (empty($certPath) || empty($keyPath)) throw new Exception('证书和私钥保存路径不能为空');

        $this->putObject($bucket, $certPath, $fullchain);
        $this->log("证书已上传到：s3://{$bucket}/{$certPath}");

        $this->putObject($bucket, $keyPath, $privatekey);
        $this->log("私钥已上传到：s3://{$bucket}/{$keyPath}");
    }

    private function putObject($bucket, $key, $content)
    {
        $path = '/' . $bucket . '/' . $key;
        $this->s3Request('PUT', $path, $content, 'application/x-pem-file');
    }

    private function s3Request($method, $path, $body, $contentType)
    {
        $time = time();
        $date = gmdate("Ymd\THis\Z", $time);
        $shortDate = gmdate("Ymd", $time);

        $host = preg_replace('#^https?://#', '', $this->endpoint);
        $scheme = (strpos($this->endpoint, 'https://') === 0) ? 'https' : 'http';
        if (strpos($this->endpoint, '://') === false) {
            $scheme = 'https';
        }

        $payloadHash = hash('sha256', $body ?? '');

        $headers = [
            'Host' => $host,
            'X-Amz-Date' => $date,
            'X-Amz-Content-Sha256' => $payloadHash,
        ];
        if ($contentType) {
            $headers['Content-Type'] = $contentType;
        }

        $authorization = $this->generateSign($method, $path, [], $headers, $body ?? '', $date, $shortDate);
        $headers['Authorization'] = $authorization;

        $url = $scheme . '://' . $host . $path;

        $headerArr = [];
        foreach ($headers as $k => $v) {
            $headerArr[] = $k . ': ' . $v;
        }

        $ch = curl_init($url);
        if ($this->proxy) {
            curl_set_proxy($ch);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArr);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null && $body !== '') {
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

        if ($httpCode >= 200 && $httpCode < 300) {
            return $response;
        }

        $errmsg = 'HTTP Code: ' . $httpCode;
        if ($response) {
            LIBXML_VERSION < 20900 && libxml_disable_entity_loader(true);
            $xml = @simplexml_load_string($response);
            if ($xml && isset($xml->Message)) {
                $errmsg = (string)$xml->Message;
            } elseif ($xml && isset($xml->Error->Message)) {
                $errmsg = (string)$xml->Error->Message;
            }
        }
        throw new Exception($errmsg);
    }

    private function generateSign($method, $path, $query, $headers, $body, $date, $shortDate)
    {
        $algorithm = 'AWS4-HMAC-SHA256';

        $canonicalUri = $this->getCanonicalURI($path);
        $canonicalQueryString = $this->getCanonicalQueryString($query);
        [$canonicalHeaders, $signedHeaders] = $this->getCanonicalHeaders($headers);
        $hashedPayload = hash('sha256', $body);

        $canonicalRequest = $method . "\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $hashedPayload;

        $credentialScope = $shortDate . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = $algorithm . "\n"
            . $date . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $this->SecretAccessKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return $algorithm . ' Credential=' . $this->AccessKeyId . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;
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
        $parts = explode('/', $path);
        $parts = array_map(function ($item) {
            return $this->escape($item);
        }, $parts);
        return implode('/', $parts);
    }

    private function getCanonicalQueryString($parameters)
    {
        if (empty($parameters)) return '';
        ksort($parameters);
        $pairs = [];
        foreach ($parameters as $key => $value) {
            $pairs[] = $this->escape($key) . '=' . $this->escape($value);
        }
        return implode('&', $pairs);
    }

    private function getCanonicalHeaders($oldHeaders)
    {
        $headers = [];
        foreach ($oldHeaders as $key => $value) {
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

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }
}
