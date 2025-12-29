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
        if (!isset($config['product']) || $config['product'] == 'cdn') {
            $this->deploy_cdn($fullchain, $privatekey, $config, $info);
        } else {
            $cert_id = $this->get_cert_id($fullchain, $privatekey);
            $info['cert_id'] = $cert_id;
            if ($config['product'] == 'blb') {
                $this->deploy_blb($cert_id, $config);
            } elseif ($config['product'] == 'appblb') {
                $this->deploy_appblb($cert_id, $config);
            } elseif ($config['product'] == 'upload') {
            } else {
                throw new Exception('不支持的产品类型');
            }
        }
    }

    public function deploy_cdn($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $config['cert_name'] = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new BaiduCloud($this->AccessKeyId, $this->SecretAccessKey, 'cdn.baidubce.com', $this->proxy);
        $param = [
            'httpsEnable' => 'ON',
            'certificate' => [
                'certName' => $config['cert_name'],
                'certServerData' => $fullchain,
                'certPrivateData' => $privatekey,
            ],
        ];
        foreach (explode(',', $config['domain']) as $domain) {
            if (empty($domain)) continue;
            try {
                $data = $client->request('GET', '/v2/' . $domain . '/certificates');
                if (isset($data['certName']) && $data['certName'] == $config['cert_name']) {
                    $this->log('CDN域名 ' . $domain . ' 证书已存在，无需重复部署');
                    return;
                }
            } catch (Exception $e) {
                $this->log($e->getMessage());
            }

            $data = $client->request('PUT', '/v2/' . $domain . '/certificates', null, $param);
            $info['cert_id'] = $data['certId'];
            $this->log('CDN域名 ' . $domain . ' 证书部署成功！');
        }
    }

    public function deploy_blb($cert_id, $config)
    {
        if (empty($config['blb_id'])) throw new Exception('负载均衡实例ID不能为空');
        if (empty($config['blb_port'])) throw new Exception('HTTPS监听端口不能为空');
        $client = new BaiduCloud($this->AccessKeyId, $this->SecretAccessKey, 'blb.' . $config['region'] . '.baidubce.com', $this->proxy);
        $query = [
            'listenerPort' => $config['blb_port'],
        ];
        $param = [
            'certIds' => [$cert_id],
        ];
        $client->request('PUT', '/v1/blb/' . $config['blb_id'] . '/HTTPSlistener', $query, $param);
        $this->log('普通型BLB ' . $config['blb_id'] . ' 部署证书成功！');
    }

    public function deploy_appblb($cert_id, $config)
    {
        if (empty($config['blb_id'])) throw new Exception('负载均衡实例ID不能为空');
        if (empty($config['blb_port'])) throw new Exception('HTTPS监听端口不能为空');
        $client = new BaiduCloud($this->AccessKeyId, $this->SecretAccessKey, 'blb.' . $config['region'] . '.baidubce.com', $this->proxy);
        $query = [
            'listenerPort' => $config['blb_port'],
        ];
        $param = [
            'certIds' => [$cert_id],
        ];
        $client->request('PUT', '/v1/appblb/' . $config['blb_id'] . '/HTTPSlistener', $query, $param);
        $this->log('应用型BLB ' . $config['blb_id'] . ' 部署证书成功！');
    }

    private function get_cert_id($fullchain, $privatekey)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new BaiduCloud($this->AccessKeyId, $this->SecretAccessKey, 'certificate.baidubce.com', $this->proxy);
        $query = [
            'certName' => $cert_name,
        ];
        try {
            $data = $client->request('GET', '/v1/certificate', $query);
        } catch (Exception $e) {
            throw new Exception('查找证书失败：' . $e->getMessage());
        }
        foreach ($data['certs'] as $row) {
            if ($row['certName'] == $cert_name) {
                $this->log('证书已存在 CertId=' . $row['certId']);
                return $row['certId'];
            }
        }

        $param = [
            'certName' => $cert_name,
            'certServerData' => $fullchain,
            'certPrivateData' => $privatekey,
        ];
        try {
            $data = $client->request('POST', '/v1/certificate', null, $param);
        } catch (Exception $e) {
            throw new Exception('上传证书失败：' . $e->getMessage());
        }
        $cert_id = $data['certId'];
        $this->log('上传证书成功 CertId=' . $cert_id);
        return $cert_id;
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
