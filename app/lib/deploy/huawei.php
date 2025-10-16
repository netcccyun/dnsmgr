<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\HuaweiCloud;
use Exception;

class huawei implements DeployInterface
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
        $client = new HuaweiCloud($this->AccessKeyId, $this->SecretAccessKey, 'scm.cn-north-4.myhuaweicloud.com', $this->proxy);
        $client->request('GET', '/v3/scm/certificates');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $config['cert_name'] = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];
        if ($config['product'] == 'cdn') {
            $this->deploy_cdn($fullchain, $privatekey, $config);
        } elseif ($config['product'] == 'elb') {
            $this->deploy_elb($fullchain, $privatekey, $config);
        } elseif ($config['product'] == 'waf') {
            $this->deploy_waf($fullchain, $privatekey, $config);
        }
    }

    private function deploy_cdn($fullchain, $privatekey, $config)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $client = new HuaweiCloud($this->AccessKeyId, $this->SecretAccessKey, 'cdn.myhuaweicloud.com', $this->proxy);
        $param = [
            'configs' => [
                'https' => [
                    'https_status' => 'on',
                    'certificate_type' => 'server',
                    'certificate_source' => 0,
                    'certificate_name' => $config['cert_name'],
                    'certificate_value' => $fullchain,
                    'private_key' => $privatekey,
                ],
            ],
        ];
        foreach (explode(',', $config['domain']) as $domain) {
            if (empty($domain)) continue;
            $client->request('PUT', '/v1.1/cdn/configuration/domains/' . $domain . '/configs', null, $param);
            $this->log('CDN域名 ' . $domain . ' 部署证书成功！');
        }
    }

    private function deploy_elb($fullchain, $privatekey, $config)
    {
        if (empty($config['project_id'])) throw new Exception('项目ID不能为空');
        if (empty($config['region_id'])) throw new Exception('区域ID不能为空');
        if (empty($config['cert_id'])) throw new Exception('证书ID不能为空');
        $endpoint = 'elb.' . $config['region_id'] . '.myhuaweicloud.com';
        $client = new HuaweiCloud($this->AccessKeyId, $this->SecretAccessKey, $endpoint, $this->proxy);
        try {
            $data = $client->request('GET', '/v3/' . $config['project_id'] . '/elb/certificates/' . $config['cert_id']);
        } catch (Exception $e) {
            throw new Exception('证书详情查询失败：' . $e->getMessage());
        }
        if (isset($data['certificate']['certificate']) && trim($data['certificate']['certificate']) == trim($fullchain)) {
            $this->log('ELB证书ID ' . $config['cert_id'] . ' 已存在，无需重复部署');
            return;
        }
        $param = [
            'certificate' => [
                'certificate' => $fullchain,
                'private_key' => $privatekey,
                'domain' => implode(',', $config['domainList']),
            ],
        ];
        $client->request('PUT', '/v3/' . $config['project_id'] . '/elb/certificates/' . $config['cert_id'], null, $param);
        $this->log('ELB证书ID ' . $config['cert_id'] . ' 更新证书成功！');
    }

    private function deploy_waf($fullchain, $privatekey, $config)
    {
        if (empty($config['project_id'])) throw new Exception('项目ID不能为空');
        if (empty($config['region_id'])) throw new Exception('区域ID不能为空');
        if (empty($config['cert_id'])) throw new Exception('证书ID不能为空');
        $endpoint = 'waf.' . $config['region_id'] . '.myhuaweicloud.com';
        $client = new HuaweiCloud($this->AccessKeyId, $this->SecretAccessKey, $endpoint, $this->proxy);
        try {
            $data = $client->request('GET', '/v1/' . $config['project_id'] . '/waf/certificates/' . $config['cert_id']);
        } catch (Exception $e) {
            throw new Exception('证书详情查询失败：' . $e->getMessage());
        }
        if (isset($data['content']) && trim($data['content']) == trim($fullchain)) {
            $this->log('WAF证书ID ' . $config['cert_id'] . ' 已存在，无需重复部署');
            return;
        }
        $param = [
            'name' => $config['cert_name'],
            'content' => $fullchain,
            'key' => $privatekey,
        ];
        $client->request('PUT', '/v1/' . $config['project_id'] . '/waf/certificates/' . $config['cert_id'], null, $param);
        $this->log('WAF证书ID ' . $config['cert_id'] . ' 更新证书成功！');
    }

    private function get_cert_id($fullchain, $privatekey)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new HuaweiCloud($this->AccessKeyId, $this->SecretAccessKey, 'scm.cn-north-4.myhuaweicloud.com', $this->proxy);
        $param = [
            'name' => $cert_name,
            'certificate' => $fullchain,
            'private_key' => $privatekey,
        ];
        try {
            $data = $client->request('POST', '/v3/scm/certificates/import', null, $param);
        } catch (Exception $e) {
            throw new Exception('上传证书失败：' . $e->getMessage());
        }
        $this->log('上传证书成功 certificate_id=' . $data['certificate_id']);
        return $data['certificate_id'];
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
