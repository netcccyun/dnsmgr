<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class proxmox implements DeployInterface
{
    private $logger;
    private $url;
    private $api_user;
    private $api_key;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->api_user = $config['api_user'];
        $this->api_key = $config['api_key'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->api_user) || empty($this->api_key)) throw new Exception('必填内容不能为空');

        $path = '/api2/json/access';
        $this->send_request($path);
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['node'])) throw new Exception('节点名称不能为空');
        $cert_hash = openssl_x509_fingerprint($fullchain, 'sha256');
        if (!$cert_hash) throw new Exception('证书解析失败');

        $path = '/api2/json/nodes/' . $config['node'] . '/certificates/info';
        $list = $this->send_request($path);
        foreach ($list as $item) {
            $fingerprint = strtolower(str_replace(':', '', $item['fingerprint']));
            if ($fingerprint == $cert_hash) {
                $this->log('节点：' . $config['node'] . ' 证书已存在');
                return;
            }
        }

        $path = '/api2/json/nodes/' . $config['node'] . '/certificates/custom';
        $params = [
            'certificates' => $fullchain,
            'key' => $privatekey,
            'force' => 1,
            'restart' => 1,
        ];
        $this->send_request($path, $params);
        $this->log('节点：' . $config['node'] . ' 证书部署成功！');
    }

    private function send_request($path, $params = null)
    {
        $url = $this->url . $path;
        $headers = ['Authorization' => 'PVEAPIToken=' . $this->api_user . '=' . $this->api_key];
        $post = $params ? http_build_query($params) : null;
        $response = http_request($url, $post, null, null, $headers, $this->proxy);
        if ($response['code'] == 200) {
            $result = json_decode($response['body'], true);
            if (isset($result['data'])) {
                return $result['data'];
            } elseif (isset($result['errors'])) {
                if (is_array($result['errors'])) {
                    $result['errors'] = implode(';', $result['errors']);
                }
                throw new Exception($result['errors']);
            } else {
                throw new Exception('返回数据解析失败');
            }
        } else {
            throw new Exception('请求失败(httpCode=' . $response['code'] . ', body=' . $response['body'] . ')');
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
