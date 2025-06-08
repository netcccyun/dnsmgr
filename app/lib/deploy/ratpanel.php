<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class ratpanel implements DeployInterface
{
    private $logger;
    private $url;
    private $id;
    private $token;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->id = $config['id'];
        $this->token = $config['token'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->id) || empty($this->token)) throw new Exception('请填写完整面板地址和访问令牌');

        $response = $this->request('/user/info', null, 'GET');
        $result = json_decode($response, true);
        if (isset($result['msg']) && $result['msg'] == "success") {
            return true;
        } else {
            throw new Exception($result['msg'] ?? '面板地址无法连接');
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if ($config['type'] == '1') {
            $this->deployPanel($fullchain, $privatekey);
            $this->log("面板证书部署成功");
            return;
        }
        $sites = explode("\n", $config['sites']);
        $success = 0;
        $errmsg = null;
        foreach ($sites as $site) {
            $site = trim($site);
            if (empty($site)) continue;
            try {
                $this->deploySite($site, $fullchain, $privatekey);
                $this->log("网站 {$site} 证书部署成功");
                $success++;
            } catch (Exception $e) {
                $errmsg = $e->getMessage();
                $this->log("网站 {$site} 证书部署失败：" . $errmsg);
            }
        }
        if ($success == 0) {
            throw new Exception($errmsg ?: '要部署的网站不存在');
        }
    }

    private function deployPanel($fullchain, $privatekey)
    {
        $data = [
            'cert' => $fullchain,
            'key' => $privatekey,
        ];
        $response = $this->request('/setting/cert', $data);
        $result = json_decode($response, true);
        if (isset($result['msg']) && $result['msg'] == "success") {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ?: '返回数据解析失败');
        }
    }

    private function deploySite($name, $fullchain, $privatekey)
    {
        $data = [
            'name' => $name,
            'cert' => $fullchain,
            'key' => $privatekey,
        ];
        $response = $this->request('/website/cert', $data);
        $result = json_decode($response, true);
        if (isset($result['msg']) && $result['msg'] == "success") {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ?: '返回数据解析失败');
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

    private function request($path, $params, $method = 'POST')
    {
        $url = $this->url . '/api' . $path;
        $body = $method == 'GET' ? null : json_encode($params);
        $sign = $this->signRequest($method, $url, $body, $this->id, $this->token);
        $response = curl_client($url, $body, null, null, [
            'Content-Type' => 'application/json',
            'X-Timestamp' => $sign['timestamp'],
            'Authorization' => 'HMAC-SHA256 Credential=' . $sign['id'] . ', Signature=' . $sign['signature']
        ], $this->proxy, $method);
        return $response['body'];
    }

    private function signRequest($method, $url, $body, $id, $token)
    {
        // 解析URL并获取路径
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'];
        $query = $parsedUrl['query'] ?? '';

        // 规范化路径
        $canonicalPath = $path;
        if (!str_starts_with($path, '/api')) {
            $apiPos = strpos($path, '/api');
            if ($apiPos !== false) {
                $canonicalPath = substr($path, $apiPos);
            }
        }

        // 构造规范化请求
        $canonicalRequest = implode("\n", [
            $method,
            $canonicalPath,
            $query,
            hash('sha256', $body ?: '')
        ]);

        // 计算签名
        $timestamp = time();
        $stringToSign = implode("\n", [
            'HMAC-SHA256',
            $timestamp,
            hash('sha256', $canonicalRequest)
        ]);
        $signature = hash_hmac('sha256', $stringToSign, $token);

        return [
            'timestamp' => $timestamp,
            'signature' => $signature,
            'id' => $id
        ];
    }
}
