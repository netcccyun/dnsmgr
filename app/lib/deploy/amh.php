<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class amh implements DeployInterface
{
    private $logger;
    private $url;
    private $apikey;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->apikey = $config['apikey'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->apikey)) throw new Exception('请填写面板地址和接口密钥');
        $this->login();
        return true;
    }

    private function login()
    {
        $path = '/?c=amapi&a=login';
        $post_data = 'amapi_expires=' . time() + 120;
        $post_data .= '&amapi_sign=' . hash_hmac('sha256', $post_data, $this->apikey);
        $response = $this->request($path, $post_data);
        if ($response['code'] == 302 && strpos($response['redirect_url'], 'amh_token=') !== false) {
            if(preg_match('/amh_token=([A-Za-z0-9]+)/', $response['redirect_url'], $matches)) {
                return $matches[1];
            }else{
                throw new Exception('面板返回数据异常');
            }
        } elseif ($response['code'] == 200 && preg_match('/<p id="error".*?>(.*?)<\/p>/s', $response['body'], $matches)) {
            throw new Exception(strip_tags($matches[1]));
        } else {
            throw new Exception('面板地址无法连接');
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['env_name'])) throw new Exception('环境名称不能为空');
        if (empty($config['vhost_name'])) throw new Exception('网站标识域名不能为空');

        $amh_token = $this->login();

        foreach (explode("\n", $config['vhost_name']) as $vhost_name) {
            $vhost_name = trim($vhost_name);
            if (empty($vhost_name)) continue;
            
            $path = '/?c=amssl&a=admin_amssl&envs_name=' . $config['env_name'] . '&vhost_name=' . $vhost_name . '&ModuleSort=app';
            $params = [
                'submit_key_crt' => 'y',
                'key_input1' => 'key_input1',
                'key_content1' => $privatekey,
                'crt_input1' => 'crt_input1',
                'crt_content1' => $fullchain,
                'amh_token' => $amh_token,
            ];
            $response = $this->request($path, $params);
            if (strpos($response['body'], '<p id="success"') !== false) {
                $this->log("网站 {$vhost_name} 证书部署成功");
            } elseif (preg_match('/<p id="error".*?>(.*?)<\/p>/s', $response['body'], $matches)) {
                $errmsg = strip_tags($matches[1]);
                $this->log("网站 {$vhost_name} 证书部署失败：" . $errmsg);
                throw new Exception($errmsg);
            } elseif (preg_match('/<p id="error".*?>(.*?)<br \/>/s', $response['body'], $matches)) {
                $errmsg = $matches[1];
                if (strpos($errmsg, '<br />') !== false) {
                    $errmsg = explode('<br />', $errmsg)[0];
                }
                $errmsg = strip_tags($errmsg);
                $this->log("网站 {$vhost_name} 证书部署失败：" . $errmsg);
                throw new Exception($errmsg);
            } else {
                throw new Exception("网站 {$vhost_name} 证书部署失败：未知错误");
            }
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

    private function request($path, $post_data = null)
    {
        $url = $this->url . $path;
        $cookie = 'PHPSESSID=' . hash_hmac('md5', 'php_sessid=' . $this->apikey, $this->apikey);
        $response = http_request($url, $post_data, null, $cookie, null, $this->proxy);
        return $response;
    }
}
