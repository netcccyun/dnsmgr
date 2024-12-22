<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\Volcengine;
use Exception;

class huoshan implements DeployInterface
{
    private $logger;
    private $AccessKeyId;
    private $SecretAccessKey;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->SecretAccessKey = $config['SecretAccessKey'];
    }

    public function check()
    {
        if (empty($this->AccessKeyId) || empty($this->SecretAccessKey)) throw new Exception('必填参数不能为空');
        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'cdn.volcengineapi.com', 'cdn', '2021-03-01', 'cn-north-1');
        $client->request('POST', 'ListCertInfo', ['Source' => 'volc_cert_center']);
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $cert_id = $this->get_cert_id($fullchain, $privatekey);
        if (!$cert_id) throw new Exception('获取证书ID失败');
        $info['cert_id'] = $cert_id;
        $this->deploy_cdn($cert_id, $config);
    }

    private function deploy_cdn($cert_id, $config)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'cdn.volcengineapi.com', 'cdn', '2021-03-01', 'cn-north-1');
        $param = [
            'CertId' => $cert_id,
            'Domain' => $config['domain'],
        ];
        $data = $client->request('POST', 'BatchDeployCert', $param);
        if (empty($data['DeployResult'])) throw new Exception('部署证书失败：DeployResult为空');
        foreach ($data['DeployResult'] as $row) {
            if ($row['Status'] == 'success') {
                $this->log('CDN域名 ' . $row['Domain'] . ' 部署证书成功！');
            } else {
                $this->log('CDN域名 ' . $row['Domain'] . ' 部署证书失败：' . isset($row['ErrorMsg']) ? $row['ErrorMsg'] : '');
            }
        }
    }

    private function get_cert_id($fullchain, $privatekey)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'cdn.volcengineapi.com', 'cdn', '2021-03-01', 'cn-north-1');
        $param = [
            'Source' => 'volc_cert_center',
            'Certificate' => $fullchain,
            'PrivateKey' => $privatekey,
            'Desc' => $cert_name,
            'Repeatable' => false,
        ];
        try {
            $data = $client->request('POST', 'AddCertificate', $param);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '证书已存在，ID为') !== false) {
                $cert_id = trim(getSubstr($e->getMessage(), '证书已存在，ID为', '。'));
                $this->log('证书已存在 CertId=' . $cert_id);
                return $cert_id;
            }
            throw new Exception('上传证书失败：' . $e->getMessage());
        }
        $this->log('上传证书成功 CertId=' . $data['CertId']);
        return $data['CertId'];
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
