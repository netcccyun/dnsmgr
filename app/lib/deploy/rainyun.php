<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class rainyun implements DeployInterface
{
    private $logger;
    private $url = 'https://api.v2.rainyun.com';
    private $apikey;
    private $proxy;

    public function __construct($config)
    {
        $this->apikey = $config['apikey'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->apikey)) throw new Exception('ApiKey不能为空');
        $this->request('/product/');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['id'])) throw new Exception('证书ID不能为空');

        $params = [
            'cert' => $fullchain,
            'key' => $privatekey,
        ];
        try {
            $this->request('/product/sslcenter/' . $config['id'], $params, 'PUT');
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $this->log('证书ID:' . $config['id'] . '更新成功！');
    }

    private function request($path, $params = null, $method = null)
    {
        $url = $this->url . $path;
        $headers = [
            'x-api-key' => $this->apikey,
        ];
        $body = null;
        if ($params) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($params);
        }
        $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 200) {
            return $result;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
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
