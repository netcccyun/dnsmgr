<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\Ctyun as CtyunClient;
use Exception;

class ctyun implements DeployInterface
{
    private $logger;
    private $AccessKeyId;
    private $SecretAccessKey;
    private $proxy;
    private $client;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->SecretAccessKey = $config['SecretAccessKey'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new CtyunClient($this->AccessKeyId, $this->SecretAccessKey, 'ctcdn-global.ctapi.ctyun.cn', $this->proxy);
    }

    public function check()
    {
        if (empty($this->AccessKeyId) || empty($this->SecretAccessKey)) throw new Exception('必填参数不能为空');
        $this->client->request('GET', '/v1/cert/query-cert-list');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $param = [
            'name' => $cert_name,
            'key' => $privatekey,
            'certs' => $fullchain,
        ];
        try {
            $this->client->request('POST', '/v1/cert/creat-cert', null, $param);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '已存在重名的证书') !== false) {
                $this->log('已存在重名的证书 cert_name=' . $cert_name);
            } else {
                throw new Exception('上传证书失败：' . $e->getMessage());
            }
        }
        $this->log('上传证书成功 cert_name=' . $cert_name);

        $param = [
            'domain' => $config['domain'],
            'https_status' => 'on',
            'cert_name' => $cert_name,
        ];
        try {
            $this->client->request('POST', '/v1/domain/update-domain', null, $param);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '请求已提交，请勿重复操作！') === false) {
                throw new Exception($e->getMessage());
            }
        }

        $this->log('CDN域名 ' . $config['domain'] . ' 部署证书成功！');
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
