<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class xp implements DeployInterface
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

        $path = '/openApi/siteList';
        $response = $this->request($path);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 1000) {
            return true;
        } else {
            throw new Exception(isset($result['message']) ? $result['message'] : '面板地址无法连接');
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $path = '/openApi/siteList';
        $response = $this->request($path);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 1000) {

            $sites = explode("\n", $config['sites']);
            $sites = array_map('trim', $sites);
            $success = 0;
            $errmsg = null;

            foreach ($result['data'] as $item) {
                if (!in_array($item['name'], $sites)) {
                    continue;
                }
                try {
                    $this->deploySite($item['id'], $fullchain, $privatekey);
                    $this->log("网站 {$item['name']} 证书部署成功");
                    $success++;
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                    $this->log("网站 {$item['name']} 证书部署失败：" . $errmsg);
                }
            }
            if ($success == 0) {
                throw new Exception($errmsg ? $errmsg : '要部署的网站不存在');
            }

        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
    }

    private function deploySite($id, $fullchain, $privatekey)
    {
        $path = '/openApi/setSSL';
        $data = [
            'id' => $id,
            'key' => $privatekey,
            'pem' => $fullchain,
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['code']) && $result['code'] == 1000) {
            return true;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
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

    private function request($path, $params = null)
    {
        $url = $this->url . $path;

        $headers = [
            'XP-API-KEY' => $this->apikey,
        ];
        $response = http_request($url, $params ? json_encode($params) : null, null, null, $headers, $this->proxy);
        return $response['body'];
    }
}
