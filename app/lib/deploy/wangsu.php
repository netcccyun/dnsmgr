<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class wangsu implements DeployInterface
{
    private $logger;
    private $username;
    private $apiKey;
    private $spKey;
    private $proxy;

    public function __construct($config)
    {
        $this->username = $config['username'];
        $this->apiKey = $config['apiKey'];
        $this->spKey = $config['spKey'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function check()
    {
        if (empty($this->username) || empty($this->apiKey)) throw new Exception('必填参数不能为空');
        $this->request('/cdn/certificates');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domain = $config['domain'];
        if (empty($domain)) {
            throw new Exception('绑定的域名不能为空');
        }

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) {
            throw new Exception('证书解析失败');
        }
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];
        
        $cert_id = $this->get_cert_id($fullchain, $privatekey, $cert_name);

        $hostnameInfo = $this->request('/cdn/hostnames/' . $domain);
        if (empty($hostnameInfo["propertyInProduction"])) {
            throw new Exception('域名 ' . $domain . ' 不存在或未部署到生产环境');
        } else {
            $this->log('CDN域名 ' . $domain . ' 对应的加速项目ID：' . $hostnameInfo["propertyInProduction"]["propertyId"]);
            $this->log('CDN域名 ' . $domain . ' 对应的加速项目生产版本：' . $hostnameInfo["propertyInProduction"]["version"]);
        }

        if ($hostnameInfo["propertyInProduction"]["certificateId"] == $cert_id) {
            $this->log('CDN域名 ' . $domain . ' 已绑定证书：' . $cert_name);
            return;
        }

        $properity = $this->request('/cdn/properties/' . $hostnameInfo["propertyInProduction"]["propertyId"] . '/versions/' . $hostnameInfo["propertyInProduction"]["version"]);
        $properityConfig = $properity["configs"];
        $properityConfig["tlsCertificateId"] = $cert_id;

        $data = $this->request('/cdn/properties/' . $hostnameInfo["propertyInProduction"]["propertyId"] . '/versions', $properityConfig, true);
        $url_parts = parse_url($data);
        $path_parts = explode('/', $url_parts['path']);
        $newVersion = end($path_parts);

        $data = $this->request('/cdn/validations', [
            'propertyId' => $hostnameInfo["propertyInProduction"]["propertyId"],
            'version' => intval($newVersion),
        ], true);
        $url_parts = parse_url($data);
        $path_parts = explode('/', $url_parts['path']);
        $validationTaskId = end($path_parts);
        $this->log('验证任务ID：' . $validationTaskId);

        $attempts = 0;
        $maxAttempts = 12;
        $status = null;

        do {
            sleep(5);
            $data = $this->request('/cdn/validations/' . $validationTaskId);
            $status = $data['status'];
            
            if ($status === 'failed') {
                throw new Exception('证书绑定失败，加速项目验证失败');
            }

            if ($status === 'succeeded') {
                break; // 验证成功立即退出循环
            }

            $attempts++;
        } while ($attempts < $maxAttempts);

        if ($status !== 'succeeded') {
            throw new Exception('证书绑定超时，加速项目验证时间过长');
        }

        $this->log('加速项目验证成功，开始部署...');
        $deploymentTasks = [
            'target' => 'production',
            'actions' => [
                [
                    'action' => 'deploy_cert',
                    'certificateId' => $cert_id,
                    'version' => 1,
                ],
                [
                    'action' => 'deploy_property',
                    'propertyId' => $hostnameInfo["propertyInProduction"]["propertyId"],
                    'version' => intval($newVersion),
                ]
            ],
            'name' => 'Deploy certificate and property for ' . $hostnameInfo["propertyInProduction"]["propertyId"],
        ];

        $data = $this->request('/cdn/deploymentTasks', $deploymentTasks, true, null, ['Check-Certificate' => 'no', 'Check-Usage' => 'no']);
        $url_parts = parse_url($data);
        $path_parts = explode('/', $url_parts['path']);
        $deploymentTaskId = end($path_parts);
        $this->log('CDN域名 ' . $domain . ' 绑定证书部署任务下发成功，部署任务ID：' . $deploymentTaskId);
        $info['cert_id'] = $cert_id;
    }

    private function get_cert_id($fullchain, $privatekey, $cert_name)
    {
        $cert_id = null;

        $data = $this->request('/cdn/certificates?search=' . urlencode($cert_name));
        if ($data['count'] > 0) {
            foreach ($data['certificates'] as $cert) {
                if ($cert_name == $cert['name']) {
                    $cert_id = $cert['certificateId'];
                    $this->log('证书' . $cert_name . '已存在，证书ID：' . $cert_id);
                }
            }
        }

        if (!$cert_id) {
            $date = gmdate("D, d M Y H:i:s T");
            $encryptedKey = $this->encryptPrivateKey($privatekey, $date);
            $param = [
                'name' => $cert_name,
                'autoRenew' => 'Off',
                'newVersion' => [
                    'privateKey' => $encryptedKey,
                    'certificate' => $fullchain,
                ]
            ];
            try {
                $data = $this->request('/cdn/certificates', $param, true, $date);
            } catch (Exception $e) {
                throw new Exception('上传证书失败：' . $e->getMessage());
            }
            $url_parts = parse_url($data);
            $path_parts = explode('/', $url_parts['path']);
            $cert_id = end($path_parts);
            $this->log('上传证书成功，证书ID：' . $cert_id);
            usleep(500000);
        }
        return $cert_id;
    }

    private function encryptPrivateKey($privateKey, $date = null)
    {
        // 获取当前 GMT 时间（DATE）
        if (empty($date)) {
            $date = gmdate("D, d M Y H:i:s T");
        }

        // 生成 HMAC-SHA256 密钥材料
        if (!empty($this->spKey)) {
            $apiKey = $this->spKey;
        } else {
            $apiKey = $this->apiKey;
        }
        $hmac = hash_hmac('sha256', $date, $apiKey, true);
        $aesIvKeyHex = bin2hex($hmac);

        if (strlen($aesIvKeyHex) != 64) {
            throw new Exception("Invalid HMAC length: " . strlen($aesIvKeyHex));
        }
        
        // 提取 IV 和 Key
        $ivHex = substr($aesIvKeyHex, 0, 32);
        $keyHex = substr($aesIvKeyHex, 32, 64);

        $iv = hex2bin($ivHex);
        $key = hex2bin($keyHex);

        $blockSize = 16; // AES 块大小为 16 字节
        $plainLen = strlen($privateKey);
        $padLen = $blockSize - ($plainLen % $blockSize);
        $padding = str_repeat(chr($padLen), $padLen);
        $plainText = $privateKey . $padding;

        // AES-128-CBC 加密
        $encrypted = openssl_encrypt(
            $plainText,
            'AES-128-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            $iv
        );

        if ($encrypted === false) {
            throw new Exception("Encryption failed: " . openssl_error_string());
        }
        
        // 返回 Base64 编码结果
        return base64_encode($encrypted);
    }

    private function request($path, $data = null, $json = false, $date = null, $headers = [])
    {
        $body = null;
        if ($data) {
            $body = $json ? json_encode($data) : http_build_query($data);
        }
        if (empty($date)) {
            $date = gmdate("D, d M Y H:i:s T");
        }
        $hmac = hash_hmac('sha1', $date, $this->apiKey, true);
        $signature = base64_encode($hmac);
        $authorization = 'Basic ' . base64_encode($this->username . ':' . $signature);
        if (empty($headers)) {
            $headers = ['Authorization: ' . $authorization, 'Date: ' . $date];
        } else {
            $headers[] = 'Authorization: ' . $authorization;
            $headers[] = 'Date: ' . $date;
        }
        if ($body && $json) {
            $headers[] = 'Content-Type: application/json';
        }
        $url = 'https://open.chinanetcenter.com' . $path;
        $response = curl_client($url, $body, null, null, $headers, $this->proxy, null, 30);
        $result = json_decode($response['body'], true);
        if (isset($response['code']) && $response['code'] == 201) {
            if (preg_match('/Location:\s*(.*)/i', $response['header'], $matches)) {
                $location = trim($matches[1]); // 提取 Location 头部的值并去除多余空格
                if (!empty($location)) {
                    return $location;
                }
            }
            // 如果没有找到 Location 头部，返回默认值 true
            return true;
        } elseif (isset($response['code']) && $response['code'] == 200) {
            return isset($result) ? $result : true;
        } elseif (isset($result['message'])) {
            throw new Exception($result['message']);
        } else {
            throw new Exception('请求失败');
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
