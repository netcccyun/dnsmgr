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

    public function __construct($config)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->SecretAccessKey = $config['SecretAccessKey'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function check()
    {
        if (empty($this->AccessKeyId) || empty($this->SecretAccessKey)) throw new Exception('必填参数不能为空');
        $client = new CtyunClient($this->AccessKeyId, $this->SecretAccessKey, 'ctcdn-global.ctapi.ctyun.cn', $this->proxy);
        $client->request('GET', '/v1/cert/query-cert-list');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $config['cert_name'] = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];
        if ($config['product'] == 'cdn') {
            $this->deploy_cdn($fullchain, $privatekey, $config);
        } elseif ($config['product'] == 'icdn') {
            $this->deploy_icdn($fullchain, $privatekey, $config);
        } elseif ($config['product'] == 'accessone') {
            $this->deploy_accessone($fullchain, $privatekey, $config);
        } elseif ($config['product'] == 'cf') {
            $this->deploy_cf($fullchain, $privatekey, $config);
        }
    }

    private function deploy_cdn($fullchain, $privatekey, $config)
    {
        $client = new CtyunClient($this->AccessKeyId, $this->SecretAccessKey, 'ctcdn-global.ctapi.ctyun.cn', $this->proxy);
        $param = [
            'name' => $config['cert_name'],
            'key' => $privatekey,
            'certs' => $fullchain,
        ];
        try {
            $client->request('POST', '/v1/cert/creat-cert', null, $param);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '已存在重名的证书') !== false) {
                $this->log('已存在重名的证书 cert_name=' . $config['cert_name']);
            } else {
                throw new Exception('上传证书失败：' . $e->getMessage());
            }
        }
        $this->log('上传证书成功 cert_name=' . $config['cert_name']);

        $param = [
            'domain' => $config['domain'],
            'https_status' => 'on',
            'cert_name' => $config['cert_name'],
        ];
        try {
            $client->request('POST', '/v1/domain/update-domain', null, $param);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '请求已提交，请勿重复操作！') === false) {
                throw new Exception($e->getMessage());
            }
        }

        $this->log('CDN域名 ' . $config['domain'] . ' 部署证书成功！');
    }

    private function deploy_icdn($fullchain, $privatekey, $config)
    {
        $client = new CtyunClient($this->AccessKeyId, $this->SecretAccessKey, 'icdn-global.ctapi.ctyun.cn', $this->proxy);
        $param = [
            'name' => $config['cert_name'],
            'key' => $privatekey,
            'certs' => $fullchain,
        ];
        try {
            $client->request('POST', '/v1/cert/creat-cert', null, $param);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '已存在重名的证书') !== false) {
                $this->log('已存在重名的证书 cert_name=' . $config['cert_name']);
            } else {
                throw new Exception('上传证书失败：' . $e->getMessage());
            }
        }
        $this->log('上传证书成功 cert_name=' . $config['cert_name']);

        $param = [
            'domain' => $config['domain'],
            'https_status' => 'on',
            'cert_name' => $config['cert_name'],
        ];
        try {
            $client->request('POST', '/v1/domain/update-domain', null, $param);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '请求已提交，请勿重复操作！') === false) {
                throw new Exception($e->getMessage());
            }
        }

        $this->log('CDN域名 ' . $config['domain'] . ' 部署证书成功！');
    }

    private function deploy_accessone($fullchain, $privatekey, $config)
    {
        $client = new CtyunClient($this->AccessKeyId, $this->SecretAccessKey, 'accessone-global.ctapi.ctyun.cn', $this->proxy);
        $param = [
            'name' => $config['cert_name'],
            'key' => $privatekey,
            'certs' => $fullchain,
        ];
        try {
            $client->request('POST', '/ctapi/v1/accessone/cert/create', null, $param);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '已存在重名的证书') !== false) {
                $this->log('已存在重名的证书 cert_name=' . $config['cert_name']);
            } else {
                throw new Exception('上传证书失败：' . $e->getMessage());
            }
        }
        $this->log('上传证书成功 cert_name=' . $config['cert_name']);

        $param = [
            'domain' => $config['domain'],
            'product_code' => '020',
        ];
        try {
            $result = $client->request('POST', '/ctapi/v1/accessone/domain/config', null, $param);
        } catch (Exception $e) {
            throw new Exception('查询域名配置失败：' . $e->getMessage());
        }

        if ($result['https_status'] == 'on' && $result['cert_name'] == $config['cert_name']) {
            $this->log('边缘安全加速域名 ' . $config['domain'] . ' 证书已部署，无需重复操作！');
            return;
        }

        $result['https_status'] = 'on';
        $result['cert_name'] = $config['cert_name'];
        $exclude_keys = ['status', 'area_scope', 'cname', 'insert_date', 'status_date', 'record_status', 'record_num', 'customer_name', 'outlink_replace_filter', 'website_ipv6_access_mark', 'websocket_speed', 'dynamic_config', 'dynamic_ability'];
        foreach ($result as $key => $value) {
            if (in_array($key, $exclude_keys) || is_array($value) && empty($value)) {
                unset($result[$key]);
            }
        }
        if (isset($result['origin'])) {
            foreach ($result['origin'] as &$origin) {
                $origin['weight'] = strval($origin['weight']);
            }
        }
        try {
            $client->request('POST', '/ctapi/v1/scdn/domain/modify_config', null, $result);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '请求已提交，请勿重复操作！') === false) {
                throw new Exception($e->getMessage());
            }
        }

        $this->log('边缘安全加速域名 ' . $config['domain'] . ' 部署证书成功！');
    }

    private function deploy_cf($fullchain, $privatekey, $config)
    {
        $client = new CtyunClient($this->AccessKeyId, $this->SecretAccessKey, 'cf-global.ctapi.ctyun.cn', $this->proxy);
        try {
            $data = $client->request('GET', '/openapi/v1/domains/customdomains/' . $config['domain'], null, null, ['regionId' => $config['region_id']]);
        } catch (Exception $e) {
            throw new Exception('获取自定义域名配置失败：' . $e->getMessage());
        }

        if (isset($data['certConfig']['certificate']) && trim($data['certConfig']['certificate']) == trim($fullchain)) {
            $this->log('函数计算域名 ' . $config['domain'] . ' 证书已部署，无需重复操作！');
            return;
        }

        if ($data['protocol'] == 'HTTP') $data['protocol'] = 'HTTP,HTTPS';
        $param = [
            'domainName' => $config['domain'],
            'description' => $data['description'],
            'protocol' => $data['protocol'],
            'certConfig' => [
                'certName' => 'cert' . substr($config['cert_name'], strpos($config['cert_name'], '-') + 1),
                'certificate' => $fullchain,
                'privateKey' => $privatekey,
            ],
            'authConfig' => $data['authConfig'],
            'routeConfig' => $data['routeConfig'],
        ];
        try {
            $client->request('PUT', '/openapi/v1/domains/customdomains/' . $config['domain'], null, $param, ['regionId' => $config['region_id']]);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '请求已提交，请勿重复操作！') === false) {
                throw new Exception($e->getMessage());
            }
        }

        $this->log('函数计算域名 ' . $config['domain'] . ' 部署证书成功！');
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
