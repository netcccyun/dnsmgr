<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\Qiniu as QiniuClient;
use Exception;

class qiniu implements DeployInterface
{
    private $logger;
    private $AccessKey;
    private $SecretKey;
    private QiniuClient $client;

    public function __construct($config)
    {
        $this->AccessKey = $config['AccessKey'];
        $this->SecretKey = $config['SecretKey'];
        $this->client = new QiniuClient($this->AccessKey, $this->SecretKey, isset($config['proxy']) ? $config['proxy'] == 1 : false);
    }

    public function check()
    {
        if (empty($this->AccessKey) || empty($this->SecretKey)) throw new Exception('必填参数不能为空');
        $this->client->request('GET', '/sslcert');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domains = $config['domain'];
        if (empty($domains)) throw new Exception('绑定的域名不能为空');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $cert_id = $this->get_cert_id($fullchain, $privatekey, $certInfo['subject']['CN'], $cert_name);

        foreach (explode(',', $domains) as $domain) {
            if (empty($domain)) continue;
            if ($config['product'] == 'cdn') {
                $this->deploy_cdn($domain, $cert_id);
            } elseif ($config['product'] == 'oss') {
                $this->deploy_oss($domain, $cert_id);
            } elseif ($config['product'] == 'pili') {
                $this->deploy_pili($config['pili_hub'], $domain, $cert_name);
            } else {
                throw new Exception('未知的产品类型');
            }
        }
        $info['cert_id'] = $cert_id;
        $info['cert_name'] = $cert_name;
    }

    private function deploy_cdn($domain, $cert_id)
    {
        try {
            $data = $this->client->request('GET', '/domain/' . $domain);
        } catch (Exception $e) {
            throw new Exception('获取域名信息失败:' . $e->getMessage());
        }
        if (isset($data['https']['certId']) && $data['https']['certId'] == $cert_id) {
            $this->log('域名 ' . $domain . ' 证书已部署，无需重复操作');
            return;
        }

        if (empty($data['https']['certId'])) {
            $param = [
                'certid' => $cert_id,
            ];
            $this->client->request('PUT', '/domain/' . $domain . '/sslize', null, $param);
        } else {
            $param = [
                'certid' => $cert_id,
                'forceHttps' => $data['https']['forceHttps'],
                'http2Enable' => $data['https']['http2Enable'],
            ];
            $this->client->request('PUT', '/domain/' . $domain . '/httpsconf', null, $param);
        }
        $this->log('CDN域名 ' . $domain . ' 证书部署成功！');
    }

    private function deploy_oss($domain, $cert_id)
    {
        $param = [
            'certid' => $cert_id,
            'domain' => $domain,
        ];
        $this->client->request('POST', '/cert/bind', null, $param);
        $this->log('OSS域名 ' . $domain . ' 证书部署成功！');
    }

    private function deploy_pili($hub, $domain, $cert_name)
    {
        $param = [
            'CertName' => $cert_name,
        ];
        $this->client->pili_request('POST', '/v2/hubs/'.$hub.'/domains/'.$domain.'/cert', null, $param);
        $this->log('视频直播域名 ' . $domain . ' 证书部署成功！');
    }

    private function get_cert_id($fullchain, $privatekey, $common_name, $cert_name)
    {
        $cert_id = null;
        $marker = '';
        do {
            $query = ['marker' => $marker, 'limit' => 100];
            $data = $this->client->request('GET', '/sslcert', $query);
            if (empty($data['certs'])) break;
            $marker = $data['marker'];
            foreach ($data['certs'] as $cert) {
                if ($cert_name == $cert['name']) {
                    $cert_id = $cert['certid'];
                    $this->log('证书' . $cert_name . '已存在，证书ID:' . $cert_id);
                } elseif ($cert['not_after'] < time()) {
                    try {
                        $this->client->request('DELETE', '/sslcert/' . $cert['certid']);
                        $this->log('证书' . $cert['name'] . '已过期，删除证书成功');
                    } catch (Exception $e) {
                        $this->log('证书' . $cert['name'] . '已过期，删除证书失败:' . $e->getMessage());
                    }
                    usleep(300000);
                }
            }
        } while ($marker != '');

        if (!$cert_id) {
            $param = [
                'name' => $cert_name,
                'common_name' => $common_name,
                'pri' => $privatekey,
                'ca' => $fullchain,
            ];
            try {
                $data = $this->client->request('POST', '/sslcert', null, $param);
            } catch (Exception $e) {
                throw new Exception('上传证书失败:' . $e->getMessage());
            }
            $this->log('上传证书成功，证书ID:' . $data['certID']);
            $cert_id = $data['certID'];
            usleep(500000);
        }
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
