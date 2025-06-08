<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class lecdn implements DeployInterface
{
    private $logger;
    private $url;
    private $email;
    private $password;
    private $proxy;
    private $accessToken;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->email = $config['email'];
        $this->password = $config['password'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->email) || empty($this->password)) throw new Exception('账号和密码不能为空');
        $this->login();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $id = $config['id'];
        if (empty($id)) throw new Exception('证书ID不能为空');

        $this->login();

        try {
            $data = $this->request('/prod-api/certificate/' . $id);
        } catch (Exception $e) {
            throw new Exception('证书ID:' . $id . '获取失败：' . $e->getMessage());
        }

        $params = [
            'id' => intval($id),
            'name' => $data['name'],
            'description' => $data['description'],
            'type' => 'upload',
            'ssl_pem' => base64_encode($fullchain),
            'ssl_key' => base64_encode($privatekey),
            'auto_renewal' => false,
        ];
        $this->request('/prod-api/certificate/' . $id, $params, 'PUT');
        $this->log("证书ID:{$id}更新成功！");
    }

    private function login()
    {
        $path = '/prod-api/login';
        $params = [
            'email' => $this->email,
            'username' => $this->email,
            'password' => $this->password,
        ];
        $result = $this->request($path, $params);
        if (isset($result['token'])) {
            $this->accessToken = $result['token'];
        } else {
            throw new Exception('登录成功，获取access_token失败');
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
        $response = curl_client($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 200) {
            return $result['data'] ?? null;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            throw new Exception('返回数据解析失败');
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
