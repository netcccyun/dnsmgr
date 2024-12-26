<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\BaiduCloud;
use Exception;

class baidu implements DeployInterface
{
    private $logger;
    private $AccessKeyId;
    private $SecretAccessKey;
    private $proxy;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->SecretAccessKey = $config['SecretAccessKey'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function check()
    {
        if (empty($this->AccessKeyId) || empty($this->SecretAccessKey)) throw new Exception('必填参数不能为空');
        $client = new BaiduCloud($this->AccessKeyId, $this->SecretAccessKey, 'cdn.baidubce.com', $this->proxy);
        $client->request('GET', '/v2/domain');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $config['cert_name'] = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new BaiduCloud($this->AccessKeyId, $this->SecretAccessKey, 'cdn.baidubce.com', $this->proxy);
        try {
            $data = $client->request('GET', '/v2/' . $config['domain'] . '/certificates');
            if (isset($data['certName']) && $data['certName'] == $config['cert_name']) {
                $this->log('CDN域名 ' . $config['domain'] . ' 证书已存在，无需重复部署');
                return;
            }
        } catch (Exception $e) {
            $this->log($e->getMessage());
        }

        $param = [
            'httpsEnable' => 'ON',
            'certificate' => [
                'certName' => $config['cert_name'],
                'certServerData' => $fullchain,
                'certPrivateData' => $privatekey,
            ],
        ];
        $data = $client->request('PUT', '/v2/' . $config['domain'] . '/certificates', null, $param);
        $info['cert_id'] = $data['certId'];
        $this->log('CDN域名 ' . $config['domain'] . ' 证书部署成功！');
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
