<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class upyun implements DeployInterface
{
    private $logger;
    private $username;
    private $password;
    private $proxy;
    private $cookie;

    public function __construct($config)
    {
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->username) || empty($this->password)) throw new Exception('用户名或密码不能为空');
        $this->login();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $this->login();

        $url = 'https://console.upyun.com/api/https/certificate/';
        $params = [
            'certificate' => $fullchain,
            'private_key' => $privatekey,
        ];
        $response = http_request($url, http_build_query($params), null, $this->cookie, null, $this->proxy);
        $result = json_decode($response['body'], true);
        if ($result['data']['status'] === 0) {
            $common_name = $result['data']['result']['commonName'];
            $certificate_id = $result['data']['result']['certificate_id'];
            $this->log('证书上传成功！证书ID:' . $certificate_id);
        } elseif (isset($result['data']['message'])) {
            throw new Exception('证书上传失败:' . $result['data']['message']);
        } else {
            throw new Exception('证书上传失败');
        }

        $url = 'https://console.upyun.com/api/https/certificate/search';
        $params = [
            'limit' => 100,
            'domain' => $common_name,
        ];
        $response = http_request($url . '?' . http_build_query($params), null, null, $this->cookie, null, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['data']['result']) && is_array($result['data']['result'])) {
            $cert_list = $result['data']['result'];
        } elseif (isset($result['data']['message'])) {
            throw new Exception('查找证书失败:' . $result['data']['message']);
        } else {
            throw new Exception('查找证书失败');
        }

        $i = 0;
        $d = 0;
        foreach ($cert_list as $crt_id => $item) {
            if ($crt_id == $certificate_id || $item['commonName'] != $common_name || $item['config_domain'] == 0) {
                continue;
            }
            $url = 'https://console.upyun.com/api/https/migrate/certificate';
            $params = [
                'new_crt_id' => $certificate_id,
                'old_crt_id' => $crt_id,
            ];
            $response = http_request($url, http_build_query($params), null, $this->cookie, null, $this->proxy);
            $result = json_decode($response['body'], true);
            if (isset($result['data']['result']) && $result['data']['result'] == true) {
                $i++;
                $d += $item['config_domain'];
                $this->log('证书ID:' . $crt_id . ' 迁移成功！');
            } elseif (isset($result['data']['message'])) {
                throw new Exception('证书迁移失败:' . $result['data']['message']);
            } else {
                throw new Exception('证书迁移失败');
            }
        }

        if ($i == 0) throw new Exception('未找到可迁移的证书');
        $this->log('共迁移' . $i . '个证书,关联域名' . $d . '个');
    }

    private function login()
    {
        $url = 'https://console.upyun.com/accounts/signin/';
        $params = [
            'username' => $this->username,
            'password' => $this->password,
        ];
        $response = http_request($url, http_build_query($params), null, null, null, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['data']['result']) && $result['data']['result'] == true) {
            $cookie = '';
            if (isset($response['headers']['set-cookie'])) {
                foreach ($response['headers']['set-cookie'] as $val) {
                    $arr = explode('=', $val);
                    if ($arr[1] == '' || $arr[1] == 'deleted') continue;
                    $cookie .= $val . '; ';
                }
            } else {
                throw new Exception('登录成功，获取cookie失败');
            }
            $this->cookie = $cookie;
            return true;
        } elseif (isset($result['data']['message'])) {
            throw new Exception('登录失败:' . $result['data']['message']);
        } else {
            throw new Exception('登录失败');
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
