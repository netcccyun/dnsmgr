<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class cachefly implements DeployInterface
{
    private $logger;
    private $url = 'https://api.cachefly.com/api/2.5';
    private $apikey;
    private $proxy;

    public function __construct($config)
    {
        $this->apikey = $config['apikey'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->apikey)) throw new Exception('API令牌不能为空');
        $this->request('/accounts/me');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $params = [
            'certificate' => $fullchain,
            'certificateKey' => $privatekey,
        ];
        $this->request('/certificates', $params);
        $this->log('证书上传成功！');
    }

    private function request($path, $params = null, $method = null)
    {
        $url = $this->url . $path;
        $headers = ['x-cf-authorization' => 'Bearer ' . $this->apikey];
        $body = null;
        if ($params) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($params);
        }
        $response = curl_client($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if ($response['code'] >= 200 && $response['code'] < 300) {
            return $result;
        } else {
            if (!empty($response['body'])) $this->log('Response:' . $response['body']);
            throw new Exception('请求失败(httpCode=' . $response['code'] . ')');
        }
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
