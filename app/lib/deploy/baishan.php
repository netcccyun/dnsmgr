<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class baishan implements DeployInterface
{
    private $logger;
    private $url = 'https://cdn.api.baishan.com';
    private $token;
    private $proxy;

    public function __construct($config)
    {
        $this->token = $config['token'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->token)) throw new Exception('token不能为空');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['id'])) throw new Exception('证书ID不能为空');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $params = [
            'cert_id' => $config['id'],
            'name' => $cert_name,
            'certificate' => $fullchain,
            'key' => $privatekey,
        ];
        try {
            $this->request('/v2/domain/certificate?token=' . $this->token, $params);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'this certificate is exists') !== false) {
                $this->log('证书ID:' . $config['id'] . '已存在，无需更新');
                return;
            }
            throw new Exception($e->getMessage());
        }

        $this->log('证书ID:' . $config['id'] . '更新成功！');
    }

    private function request($path, $params = null)
    {
        $url = $this->url . $path;
        $headers = [];
        $body = null;
        if ($params) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($params);
        }
        $response = curl_client($url, $body, null, null, $headers, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['code']) && $result['code'] == 0) {
            return $result;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            if (!empty($response['body'])) $this->log('Response:' . $response['body']);
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
