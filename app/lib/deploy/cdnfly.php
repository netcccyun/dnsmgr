<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class cdnfly implements DeployInterface
{
    private $logger;
    private $url;
    private $api_key;
    private $api_secret;
    private $auth = 0;
    private $username;
    private $password;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->api_key = $config['api_key'];
        $this->api_secret = $config['api_secret'];
        $this->auth = isset($config['auth']) ? $config['auth'] : 0;
        if ($this->auth == 1) {
            $this->username = $config['username'];
            $this->password = $config['password'];
        }
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if ($this->auth == 1) {
            if (empty($this->url) || empty($this->username) || empty($this->password)) throw new Exception('必填参数不能为空');
            $this->login();
        } else {
            if (empty($this->url) || empty($this->api_key) || empty($this->api_secret)) throw new Exception('必填参数不能为空');
            $this->request('/v1/user');
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $id = $config['id'];
        if (empty($id)) {
            $certInfo = openssl_x509_parse($fullchain, true);
            if (!$certInfo) throw new Exception('证书解析失败');
            $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];
            $params = [
                'type' => 'custom',
                'name' => $cert_name,
                'cert' => $fullchain,
                'key' => $privatekey,
            ];
            if ($this->auth == 1) {
                $access_token = $this->login();
                $url = $this->url . '/v1/certs';
                $body = json_encode($params);
                $headers = [
                    'Access-Token' => $access_token,
                ];
                $response = http_request($url, $body, null, null, $headers, $this->proxy, 'POST');
                $result = json_decode($response['body'], true);
                if (isset($result['code']) && $result['code'] == 0) {
                    $id = $result['data'];
                } elseif (isset($result['msg'])) {
                    throw new Exception('证书添加失败，' . $result['msg']);
                } else {
                    throw new Exception('证书添加失败，返回数据解析失败');
                }
            } else {
                $id = $this->request('/v1/certs', $params, 'POST');
            }
            $this->log("证书ID:{$id}添加成功！");
            $info['config']['id'] = $id;
            return;
        }

        $params = [
            'type' => 'custom',
            'cert' => $fullchain,
            'key' => $privatekey,
        ];
        if ($this->auth == 1) {
            $access_token = $this->login();
            $url = $this->url . '/v1/certs/' . $id;
            $body = json_encode($params);
            $headers = [
                'Access-Token' => $access_token,
            ];
            $response = http_request($url, $body, null, null, $headers, $this->proxy, 'PUT');
            $result = json_decode($response['body'], true);
            if (isset($result['code']) && $result['code'] == 0) {
            } elseif (isset($result['msg'])) {
                throw new Exception('证书ID:' . $id . '更新失败，' . $result['msg']);
            } else {
                throw new Exception('证书ID:' . $id . '更新失败，返回数据解析失败');
            }
        } else {
            $this->request('/v1/certs/' . $id, $params, 'PUT');
        }
        $this->log("证书ID:{$id}更新成功！");
    }

    public function login()
    {
        $url = $this->url . '/v1/login';
        $params = [
            'account' => $this->username,
            'password' => $this->password,
        ];
        $body = json_encode($params);
        $response = http_request($url, $body, null, null, null, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 0) {
            return $result['data']['access_token'];
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('登录失败，返回数据解析失败');
        }
    }

    private function request($path, $params = null, $method = null)
    {
        $url = $this->url . $path;
        $headers = ['api-key' => $this->api_key, 'api-secret' => $this->api_secret];
        $body = null;
        if ($params) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($params);
        }
        $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 0) {
            return isset($result['data']) ? $result['data'] : null;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
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
