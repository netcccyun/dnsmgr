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
        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'open.volcengineapi.com', 'cdn', '2021-03-01', 'cn-north-1', $this->proxy);
        $client->request('POST', 'ListCertInfo', ['Source' => 'volc_cert_center']);
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if ($config['product'] == 'live') {
            $this->deploy_live($fullchain, $privatekey, $config);
        } else {
            $cert_id = $this->get_cert_id($fullchain, $privatekey);
            if (!$cert_id) throw new Exception('获取证书ID失败');
            $info['cert_id'] = $cert_id;
            if (!isset($config['product']) || $config['product'] == 'cdn') {
                $this->deploy_cdn($cert_id, $config);
            } elseif ($config['product'] == 'dcdn') {
                $this->deploy_dcdn($cert_id, $config);
            } elseif ($config['product'] == 'tos') {
                $this->deploy_tos($cert_id, $config);
            } elseif ($config['product'] == 'imagex') {
                $this->deploy_imagex($cert_id, $config);
            } elseif ($config['product'] == 'clb') {
                $this->deploy_clb($cert_id, $config);
            }
        }
    }

    private function deploy_cdn($cert_id, $config)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'cdn.volcengineapi.com', 'cdn', '2021-03-01', 'cn-north-1', $this->proxy);
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
                $this->log('CDN域名 ' . $row['Domain'] . ' 部署证书失败：' . (isset($row['ErrorMsg']) ? $row['ErrorMsg'] : ''));
            }
        }
    }

    private function deploy_dcdn($cert_id, $config)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'open.volcengineapi.com', 'dcdn', '2021-04-01', 'cn-north-1', $this->proxy);
        $param = [
            'CertId' => $cert_id,
            'DomainNames' => explode(',', $config['domain']),
        ];
        $client->request('POST', 'CreateCertBind', $param);
        $this->log('DCDN域名 ' . $config['domain'] . ' 部署证书成功！');
    }

    private function deploy_tos($cert_id, $config)
    {
        if (empty($config['bucket_domain'])) throw new Exception('Bucket域名不能为空');
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, $config['bucket_domain'], 'tos', '2021-04-01', 'cn-beijing', $this->proxy);
        foreach (explode(',', $config['domain']) as $domain) {
            $param = [
                'CustomDomainRule' => [
                    'Domain' => $domain,
                    'CertId' => $cert_id,
                ]
            ];
            $query = ['customdomain' => ''];
            $client->tos_request('PUT', $param, $query);
            $this->log('对象存储域名 ' . $config['domain'] . ' 部署证书成功！');
        }
    }

    private function deploy_live($fullchain, $privatekey, $config)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'live.volcengineapi.com', 'live', '2023-01-01', 'cn-north-1', $this->proxy);
        $param = [
            'CertName' => $cert_name,
            'Rsa' => [
                'Pubkey' => $fullchain,
                'Prikey' => $privatekey,
            ],
            'UseWay' => 'https',
        ];
        $result = $client->request('POST', 'CreateCert', $param);
        $this->log('上传证书成功 ChainID=' . $result['ChainID']);

        foreach (explode(',', $config['domain']) as $domain) {
            $param = [
                'ChainID' => $result['ChainID'],
                'Domain' => $domain,
                'HTTPS' => true,
                'HTTP2' => true,
            ];
            $client->request('POST', 'BindCert', $param);
            $this->log('视频直播域名 ' . $domain . ' 部署证书成功！');
        }
    }

    private function deploy_imagex($cert_id, $config)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'imagex.volcengineapi.com', 'imagex', '2018-08-01', 'cn-north-1', $this->proxy);
        foreach (explode(',', $config['domain']) as $domain) {
            $param = [
                [
                    'domain' => $domain,
                    'cert_id' => $cert_id,
                ]
            ];
            $result = $client->request('POST', 'UpdateImageBatchDomainCert', $param);
            if (isset($result['SuccessDomains']) && count($result['SuccessDomains']) > 0) {
                $this->log('veImageX域名 ' . $domain . ' 部署证书成功！');
            } elseif (isset($result['FailedDomains']) && count($result['FailedDomains']) > 0) {
                $errmsg = $result['FailedDomains'][0]['ErrMsg'];
                $this->log('veImageX域名 ' . $domain . ' 部署证书失败：' . $errmsg);
            } else {
                $this->log('veImageX域名 ' . $domain . ' 部署证书失败');
            }
        }
    }

    private function deploy_clb($cert_id, $config)
    {
        if (empty($config['listener_id'])) throw new Exception('监听器ID不能为空');
        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'open.volcengineapi.com', 'clb', '2020-04-01', 'cn-beijing', $this->proxy);
        $param = [
            'ListenerId' => $config['listener_id'],
            'CertificateSource' => 'cert_center',
            'CertCenterCertificateId' => $cert_id,
        ];
        $client->request('GET', 'ModifyListenerAttributes', $param);
        $this->log('CLB监听器 ' . $config['listener_id'] . ' 部署证书成功！');
    }

    private function get_cert_id($fullchain, $privatekey)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, 'open.volcengineapi.com', 'certificate_service', '2024-10-01', 'cn-beijing', $this->proxy);
        $param = [
            'Tag' => $cert_name,
            'Repeatable' => false,
            'CertificateInfo' => [
                'CertificateChain' => $fullchain,
                'PrivateKey' => $privatekey,
            ],
        ];
        try {
            $data = $client->request('POST', 'ImportCertificate', $param);
        } catch (Exception $e) {
            throw new Exception('上传证书失败：' . $e->getMessage());
        }
        if (!empty($data['InstanceId'])) {
            $cert_id = $data['InstanceId'];
        } else {
            $cert_id = $data['RepeatId'];
        }
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
