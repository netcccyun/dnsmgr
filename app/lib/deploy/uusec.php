<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class uusec implements DeployInterface
{
    private $logger;
    private $url;
    private $username;
    private $password;
    private $proxy;
    private $accessToken;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->password) || empty($this->password)) throw new Exception('用户名和密码不能为空');
        $this->login();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $id = $config['id'];
        if (empty($id)) throw new Exception('证书ID不能为空');

        $this->login();

        $params = [
            'id' => intval($id),
            'type' => 1,
            'name' => $config['name'],
            'crt' => $fullchain,
            'key' => $privatekey,
        ];
        $result = $this->request('/api/v1/certs', $params, 'PUT');
        if (is_string($result) && $result == 'OK') {
            $this->log('证书ID:' . $id . '更新成功！');
        } else {
            throw new Exception('证书ID:' . $id . '更新失败，' . (isset($result['err']) ? $result['err'] : '未知错误'));
        }
    }

    private function login()
    {
        $path = '/api/v1/users/login';
        $params = [
            'usr' => $this->username,
            'pwd' => $this->password,
            'otp' => '',
        ];
        $result = $this->request($path, $params);
        if (isset($result['token'])) {
            $this->accessToken = $result['token'];
        } else {
            throw new Exception('登录失败，' . (isset($result['err']) ? $result['err'] : '未知错误'));
        }
    }

    private function request($path, $params = null, $method = null)
    {
        $url = $this->url . $path;
        $headers = [];
        $body = null;
        if ($this->accessToken) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        }
        if ($params) {
            $headers['Content-Type'] = 'application/json;charset=UTF-8';
            $body = json_encode($params);
        }
        $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if ($response['code'] == 200) {
            return $result;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            throw new Exception('请求失败，HTTP状态码：' . $response['code']);
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
