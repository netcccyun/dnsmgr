<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class doge implements DeployInterface
{
    private $logger;
    private $AccessKey;
    private $SecretKey;
    private $proxy;

    public function __construct($config)
    {
        $this->AccessKey = $config['AccessKey'];
        $this->SecretKey = $config['SecretKey'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function check()
    {
        if (empty($this->AccessKey) || empty($this->SecretKey)) throw new Exception('必填参数不能为空');
        $this->request('/cdn/cert/list.json');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domains = $config['domain'];
        if (empty($domains)) throw new Exception('绑定的域名不能为空');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $cert_id = $this->get_cert_id($fullchain, $privatekey, $cert_name);

        foreach (explode(',', $domains) as $domain) {
            if (empty($domain)) continue;
            $param = [
                'id' => $cert_id,
                'domain' => $domain,
            ];
            $this->request('/cdn/cert/bind.json', $param);
            $this->log('CDN域名 ' . $domain . ' 绑定证书成功！');
        }
        $info['cert_id'] = $cert_id;
    }

    private function get_cert_id($fullchain, $privatekey, $cert_name)
    {
        $cert_id = null;

        $data = $this->request('/cdn/cert/list.json');
        foreach ($data['certs'] as $cert) {
            if ($cert_name == $cert['note']) {
                $cert_id = $cert['id'];
                $this->log('证书' . $cert_name . '已存在，证书ID:' . $cert_id);
            } elseif ($cert['expire'] < time() && $cert['domainCount'] == 0) {
                try {
                    $this->request('/cdn/cert/delete.json', ['id' => $cert['id']]);
                    $this->log('证书' . $cert['name'] . '已过期，删除证书成功');
                } catch (Exception $e) {
                    $this->log('证书' . $cert['name'] . '已过期，删除证书失败:' . $e->getMessage());
                }
                usleep(300000);
            }
        }

        if (!$cert_id) {
            $param = [
                'note' => $cert_name,
                'cert' => $fullchain,
                'private' => $privatekey,
            ];
            try {
                $data = $this->request('/cdn/cert/upload.json', $param);
            } catch (Exception $e) {
                throw new Exception('上传证书失败:' . $e->getMessage());
            }
            $this->log('上传证书成功，证书ID:' . $data['id']);
            $cert_id = $data['id'];
            usleep(500000);
        }
        return $cert_id;
    }

    private function request($path, $data = null, $json = false)
    {
        $body = null;
        if($data){
            $body = $json ? json_encode($data) : http_build_query($data);
        }
        $signStr = $path . "\n" . $body;
        $sign = hash_hmac('sha1', $signStr, $this->SecretKey);
        $authorization = "TOKEN " . $this->AccessKey . ":" . $sign;
        $headers = ['Authorization' => $authorization];
        if($body && $json) $headers['Content-Type'] = 'application/json';
        $url = 'https://api.dogecloud.com'.$path;
        $response = http_request($url, $body, null, null, $headers, $this->proxy);
        $result = json_decode($response['body'], true);
        if(isset($result['code']) && $result['code'] == 200){
            return $result['data'] ?? true;
        }elseif(isset($result['msg'])){
            throw new Exception($result['msg']);
        }else{
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
