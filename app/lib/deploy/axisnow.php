<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class axisnow implements DeployInterface
{
    private $logger;
    private $token;
    private $proxy;

    public function __construct($config)
    {
        $this->token = $config['token'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function check()
    {
        if (empty($this->token)) throw new Exception('Token不能为空');
        // 通过查询证书列表来验证Token有效性，返回200即Token有效
        $url = 'https://api.axisnow.io/client/v1/certificates';
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];
        $response = http_request($url, null, null, null, $headers, $this->proxy, 'GET');
        if (intval($response['code']) == 200) {
            return true;
        }
        $result = json_decode($response['body'], true);
        throw new Exception('Token无效或请求失败:' . $this->getErrorMessage($result, $response['body']));
    }

    // 从响应中提取错误信息
    private function getErrorMessage($result, $body)
    {
        $errorMsg = '';
        if (isset($result['errors']) && is_array($result['errors'])) {
            foreach ($result['errors'] as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $errorMsg .= $error['message'] . '; ';
                } elseif (is_string($error)) {
                    $errorMsg .= $error . '; ';
                }
            }
        }
        $errorMsg = trim($errorMsg, '; ');
        if (empty($errorMsg) && !empty($body)) {
            $errorMsg = is_string($body) ? mb_substr($body, 0, 300) : '';
        }
        if (empty($errorMsg)) $errorMsg = '请求失败';
        return $errorMsg;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $uuid = isset($info['uuid']) ? $info['uuid'] : null;

        if ($uuid) {
            // 尝试获取已存在的证书，存在则更新证书内容
            try {
                $this->request('GET', '/client/v1/certificates/' . $uuid, null);
                $this->log('证书' . $cert_name . '已存在，UUID:' . $uuid);
                $param = [
                    'name' => $cert_name,
                    'certificate' => $fullchain,
                    'private_key' => $privatekey,
                ];
                $this->request('PUT', '/client/v1/certificates/' . $uuid, $param);
                $this->log('证书更新成功，UUID:' . $uuid);
            } catch (Exception $e) {
                $this->log('原证书获取或更新失败(' . $e->getMessage() . ')，将重新上传证书');
                $uuid = null;
            }
        }

        if (!$uuid) {
            // 创建新证书
            $param = [
                'type' => 'upload',
                'name' => $cert_name,
                'certificate' => $fullchain,
                'private_key' => $privatekey,
            ];
            try {
                $result = $this->request('POST', '/client/v1/certificates', $param);
            } catch (Exception $e) {
                throw new Exception('上传证书失败:' . $e->getMessage());
            }
            $uuid = $result['uuid'];
            $this->log('上传证书成功，UUID:' . $uuid);
        }

        // 保存UUID，供下一次更新证书使用
        $info['uuid'] = $uuid;
    }

    private function request($method, $path, $data = null)
    {
        $url = 'https://api.axisnow.io' . $path;
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];
        $body = null;
        if ($data) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($data);
        }
        $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if (isset($result['success']) && $result['success']) {
            return $result['result'] ?? true;
        }
        throw new Exception($this->getErrorMessage($result, $response['body']));
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
