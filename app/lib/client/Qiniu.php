<?php

namespace app\lib\client;

use Exception;

/**
 * 七牛云
 */
class Qiniu
{
    private $ApiUrl = 'https://api.qiniu.com';
    private $AccessKey;
    private $SecretKey;
    private $proxy = false;

    public function __construct($AccessKey, $SecretKey, $proxy = false)
    {
        $this->AccessKey = $AccessKey;
        $this->SecretKey = $SecretKey;
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
        $url = $this->ApiUrl . $path;
        $query_str = null;
        $body = null;
        if (!empty($query)) {
            $query = array_filter($query, function ($a) {
                return $a !== null;
            });
            $query_str = http_build_query($query);
            $url .= '?' . $query_str;
        }
        if (!empty($params)) {
            $params = array_filter($params, function ($a) {
                return $a !== null;
            });
            $body = json_encode($params);
        }

        $sign_str = $path . ($query_str ? '?' . $query_str : '') . "\n";
        $hmac = hash_hmac('sha1', $sign_str, $this->SecretKey, true);
        $sign = $this->AccessKey . ':' . $this->base64_urlSafeEncode($hmac);

        $header = [
            'Authorization: QBox ' . $sign,
        ];
        if ($body) {
            $header[] = 'Content-Type: application/json';
        }
        return $this->curl($method, $url, $body, $header);
    }

    private function base64_urlSafeEncode($data)
    {
        $find = array('+', '/');
        $replace = array('-', '_');
        return str_replace($find, $replace, base64_encode($data));
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
        curl_setopt($ch, CURLOPT_USERAGENT, 'QiniuPHP/7.14.0 (' . php_uname("s") . '/' . php_uname("m") . ') PHP/' . phpversion());
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

        if ($httpCode == 200) {
            $arr = json_decode($response, true);
            if($arr) return $arr;
            return true;
        } else {
            $arr = json_decode($response, true);
            if ($arr && !empty($arr['error'])) {
                throw new Exception($arr['error']);
            } else {
                throw new Exception('返回数据解析失败');
            }
        }
    }
}
