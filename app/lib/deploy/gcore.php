<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class gcore implements DeployInterface
{
    private $logger;
    private $url = 'https://api.gcore.com';
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
        $this->request('/iam/clients/me');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $id = $config['id'];
        if (empty($id)) throw new Exception('证书ID不能为空');

        $params = [
            'name' => $config['name'],
            'sslCertificate' => $fullchain,
            'sslPrivateKey' => $privatekey,
            'validate_root_ca' => true,
        ];
        $this->request('/cdn/sslData/' . $id, $params, 'PUT');
        $this->log('证书ID:' . $id . '更新成功！');
    }

    private function request($path, $params = null, $method = null)
    {
        $url = $this->url . $path;
        $headers = ['Authorization' => 'APIKey ' . $this->apikey];
        $body = null;
        if ($params) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($params);
        }
        $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if ($response['code'] >= 200 && $response['code'] < 300) {
            return $result;
        } elseif (isset($result['message']['message'])) {
            throw new Exception($result['message']['message']);
        } elseif (isset($result['errors'])) {
            $errors = $result['errors'][array_key_first($result['errors'])];
            throw new Exception($errors[0]);
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
