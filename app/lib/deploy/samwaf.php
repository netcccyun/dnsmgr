<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class samwaf implements DeployInterface
{
    private $logger;
    private $url;
    private $api_key;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->api_key = $config['api_key'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->api_key)) throw new Exception('请填写控制台地址和API Key');
        $this->request('/sysinfo/version', null, 'GET');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $id = $config['id'] ?? '';
        $params = [
            'cert_content' => $fullchain,
            'key_content' => $privatekey,
        ];
        if (empty($id)) {
            try {
                $this->request('/sslconfig/add', $params);
            } catch (Exception $e) {
                throw new Exception('证书添加失败，' . $e->getMessage());
            }
            $id = $this->findCertId($fullchain);
            if (empty($id)) throw new Exception('证书添加成功但未能获取证书ID');
            $this->log("证书ID:{$id}添加成功！");
            $info['config']['id'] = $id;
            return;
        }

        $params['id'] = $id;
        $this->request('/sslconfig/edit', $params);
        $this->log("证书ID:{$id}更新成功！");
    }

    private function findCertId($fullchain)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $domains = $certInfo['subject']['CN'];
        $serial_no = hexdec($certInfo['serialNumberHex']);
        $params = [
            'domains' => $domains,
            'pageIndex' => 1,
            'pageSize' => 10,
        ];
        $result = $this->request('/sslconfig/list', $params, 'POST');
        foreach ($result['list'] as $cert) {
            if ($serial_no == $cert['serial_no']) {
                return $cert['id'];
            }
        }
        return null;
    }

    private function request($path, $params = null, $method = null)
    {
        $url = $this->url . '/api/v1' . $path;
        $headers = ['X-API-Key' => $this->api_key];
        $body = null;
        if ($method === 'GET' && $params) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        } elseif ($params) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($params);
        }
        $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 0) {
            return $result['data'] ?? null;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('请求失败(httpCode=' . $response['code'] . ')');
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
