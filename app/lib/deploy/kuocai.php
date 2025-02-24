<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class kuocai implements DeployInterface
{
    private $logger;
    private $username;
    private $password;
    private $proxy;
    private $token = null;

    public function __construct($config)
    {
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->username) || empty($this->password)) {
            throw new Exception('请填写控制台账号和密码');
        }
        $this->request('/login/loginUser', [
            'userAccount' => $this->username,
            'userPwd' => $this->password,
            'remember' => 'true'
        ]);
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $id = $config['id'];
        if (empty($id)) {
            throw new Exception('域名ID不能为空');
        }
        $this->token = $this->request('/login/loginUser', [
            'userAccount' => $this->username,
            'userPwd' => $this->password,
            'remember' => 'true'
        ]);
        $this->request('/CdnDomainHttps/httpsConfiguration', [
            'doMainId' => $id,
            'https' => [
                'certificate_name' => uniqid('cert_'),
                'certificate_source' => '0',
                'certificate_value' => $fullchain,
                'https_status' => 'on',
                'private_key' => $privatekey,
            ]
        ], true);
        $this->log("域名ID:{$id}更新成功！");
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

    private function request($path, $params = null, $json = false)
    {
        $url = 'https://kuocai.cn' . $path;
        $body = $json ? json_encode($params) : $params;
        $headers = [];
        if ($json) $headers[] = 'Content-Type: application/json';
        $response = curl_client(
            $url,
            $body,
            null,
            $this->token ? "kuocai_cdn_token={$this->token}" : null,
            $headers,
            $this->proxy
        );
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 'SUCCESS') {
            return isset($result['data']) ? $result['data'] : null;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            throw new Exception('请求失败(httpCode=' . $response['code'] . ')');
        }
    }
}
