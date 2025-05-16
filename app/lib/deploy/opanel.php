<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class opanel implements DeployInterface
{
    private $logger;
    private $url;
    private $key;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/') . '/api/' . $config['version'] ?: 'v1';
        $this->key = $config['key'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->key)) throw new Exception('请填写面板地址和接口密钥');
        $this->request("/settings/search");
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domains = $config['domainList'];
        if (empty($domains)) throw new Exception('没有设置要部署的域名');

        $params = ['page' => 1, 'pageSize' => 500];
        try {
            $data = $this->request("/websites/ssl/search", $params);
            $this->log('获取证书列表成功(total=' . $data['total'] . ')');
        } catch (Exception $e) {
            throw new Exception('获取证书列表失败：' . $e->getMessage());
        }

        $success = 0;
        $errmsg = null;
        if (!empty($data['items'])) {
            foreach ($data['items'] as $row) {
                if (empty($row['primaryDomain'])) continue;
                $cert_domains = [];
                $cert_domains[] = $row['primaryDomain'];
                if (!empty($row['domains'])) $cert_domains += explode(',', $row['domains']);
                $flag = false;
                foreach ($cert_domains as $domain) {
                    if (in_array($domain, $domains)) {
                        $flag = true;
                        break;
                    }
                }
                if ($flag) {
                    $params = [
                        'sslID' => $row['id'],
                        'type' => 'paste',
                        'certificate' => $fullchain,
                        'privateKey' => $privatekey,
                        'description' => '',
                    ];
                    try {
                        $this->request('/websites/ssl/upload', $params);
                        $this->log("证书ID:{$row['id']}更新成功！");
                        $success++;
                    } catch (Exception $e) {
                        $errmsg = $e->getMessage();
                        $this->log("证书ID:{$row['id']}更新失败：" . $errmsg);
                    }
                }
            }
        }
        if ($success == 0) {
            throw new Exception($errmsg ? $errmsg : '没有要更新的证书');
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

        $timestamp = time() . '';
        $token = md5('1panel' . $this->key . $timestamp);
        $headers = [
            '1Panel-Token: ' . $token,
            '1Panel-Timestamp: ' . $timestamp
        ];
        $body = $params ? json_encode($params) : '{}';
        if ($body) $headers[] = 'Content-Type: application/json';
        $response = curl_client($url, $body, null, null, $headers, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 200) {
            return isset($result['data']) ? $result['data'] : null;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            throw new Exception('请求失败(httpCode=' . $response['code'] . ')');
        }
    }
}
