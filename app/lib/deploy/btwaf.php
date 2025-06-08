<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class btwaf implements DeployInterface
{
    private $logger;
    private $url;
    private $key;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->key = $config['key'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->key)) throw new Exception('请填写面板地址和接口密钥');

        $path = '/api/user/latest_version';
        $response = $this->request($path, []);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 0) {
            return true;
        } else {
            throw new Exception(isset($result['res']) ? $result['res'] : '面板地址无法连接');
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $sites = explode("\n", $config['sites']);
        $success = 0;
        $errmsg = null;
        foreach ($sites as $site) {
            $siteName = trim($site);
            if (empty($siteName)) continue;
            try {
                $this->deploySite($siteName, $fullchain, $privatekey);
                $this->log("网站 {$siteName} 证书部署成功");
                $success++;
            } catch (Exception $e) {
                $errmsg = $e->getMessage();
                $this->log("网站 {$siteName} 证书部署失败：" . $errmsg);
            }
        }
        if ($success == 0) {
            throw new Exception($errmsg ? $errmsg : '要部署的网站不存在');
        }
    }

    private function deploySite($siteName, $fullchain, $privatekey)
    {
        $site_id = null;
        $listen_ssl_port = ['443'];
        $path = '/api/wafmastersite/get_site_list';
        $data = ['p' => 1, 'p_size' => 10, 'site_name' => $siteName];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 0) {
            foreach ($result['res']['list'] as $site) {
                if ($site['site_name'] == $siteName) {
                    $site_id = $site['site_id'];
                    if (isset($site['server']['listen_ssl_port']) && !empty($site['server']['listen_ssl_port'])) {
                        $listen_ssl_port = $site['server']['listen_ssl_port'];
                    }
                    break;
                }
            }
            if (!$site_id) {
                throw new Exception("网站名称不存在");
            }
        } elseif (isset($result['res'])) {
            throw new Exception($result['res']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
        $path = '/api/wafmastersite/modify_site';
        $data = [
            'types' => 'openCert',
            'site_id' => $site_id,
            'server' => [
                'listen_ssl_port' => $listen_ssl_port,
                'ssl' => [
                    'is_ssl' => 1,
                    'private_key' => $privatekey,
                    'full_chain' => $fullchain,
                ],
            ]
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 0) {
            return true;
        } elseif (isset($result['res'])) {
            throw new Exception($result['res']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
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

    private function request($path, $params)
    {
        $url = $this->url . $path;

        $now_time = time();
        $headers = [
            'waf_request_time' => $now_time,
            'waf_request_token' => md5($now_time . md5($this->key)),
            'Content-Type' => 'application/json',
        ];
        $post = $params ? json_encode($params) : null;
        $response = http_request($url, $post, null, null, $headers, $this->proxy, 'POST');
        return $response['body'];
    }
}
