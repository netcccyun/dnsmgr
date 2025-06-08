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
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->api_key = $config['api_key'];
        $this->api_secret = $config['api_secret'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->api_key) || empty($this->api_secret)) throw new Exception('必填参数不能为空');
        $this->request('/v1/user');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $id = $config['id'];
        if (empty($id)) throw new Exception('证书ID不能为空');

        $params = [
            'type' => 'custom',
            'cert' => $fullchain,
            'key' => $privatekey,
        ];
        $this->request('/v1/certs/' . $id, $params, 'PUT');
        $this->log("证书ID:{$id}更新成功！");
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
        $response = curl_client($url, $body, null, null, $headers, $this->proxy, $method);
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
