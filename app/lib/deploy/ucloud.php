<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\Ucloud as UcloudClient;
use Exception;

class ucloud implements DeployInterface
{
    private $logger;
    private $PublicKey;
    private $PrivateKey;
    private UcloudClient $client;

    public function __construct($config)
    {
        $this->PublicKey = $config['PublicKey'];
        $this->PrivateKey = $config['PrivateKey'];
        $this->client = new UcloudClient($this->PublicKey, $this->PrivateKey);
    }

    public function check()
    {
        if (empty($this->PublicKey) || empty($this->PrivateKey)) throw new Exception('必填参数不能为空');
        $param = ['Mode' => 'free'];
        $this->client->request('GetCertificateList', $param);
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domain_id = $config['domain_id'];
        if (empty($domain_id)) throw new Exception('云分发资源ID不能为空');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace(['*', '.'], '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        try {
            $data = $this->client->request('GetCertificateV2', []);
        } catch (Exception $e) {
            throw new Exception('获取证书列表失败 ' . $e->getMessage());
        }

        $exist = false;
        foreach ($data['CertList'] as $cert) {
            if (trim($cert['UserCert']) == trim($fullchain)) {
                $cert_name = $cert['CertName'];
                $exist = true;
            }
        }

        if (!$exist) {
            $param = [
                'CertName' => $cert_name,
                'UserCert' => $fullchain,
                'PrivateKey' => $privatekey,
            ];
            try {
                $data = $this->client->request('AddCertificate', $param);
            } catch (Exception $e) {
                throw new Exception('添加证书失败 ' . $e->getMessage());
            }
            $this->log('添加证书成功，名称:' . $cert_name);
        } else {
            $this->log('获取到已添加的证书，名称:' . $cert_name);
        }

        try {
            $data = $this->client->request('GetUcdnDomainConfig', ['DomainId.0' => $domain_id]);
        } catch (Exception $e) {
            throw new Exception('获取加速域名配置失败 ' . $e->getMessage());
        }
        if (empty($data['DomainList'])) throw new Exception('云分发资源ID:' . $domain_id . '不存在');
        $domain = $data['DomainList'][0]['Domain'];
        $HttpsStatusCn = $data['DomainList'][0]['HttpsStatusCn'];
        $HttpsStatusAbroad = $data['DomainList'][0]['HttpsStatusAbroad'];

        if ($data['DomainList'][0]['CertNameCn'] == $cert_name || $data['DomainList'][0]['CertNameAbroad'] == $cert_name) {
            $this->log('云分发' . $domain_id . '证书已配置，无需重复操作');
            return;
        }

        try {
            $data = $this->client->request('GetCertificateBaseInfoList', ['Domain' => $domain]);
        } catch (Exception $e) {
            throw new Exception('获取可用证书列表失败 ' . $e->getMessage());
        }
        if (empty($data['CertList'])) throw new Exception('可用证书列表为空');

        $cert_id = null;
        foreach ($data['CertList'] as $cert) {
            if ($cert['CertName'] == $cert_name) {
                $cert_id = $cert['CertId'];
                break;
            }
        }
        if (!$cert_id) throw new Exception('证书ID不存在');
        $this->log('证书ID获取成功:' . $cert_id);

        $param = [
            'DomainId' => $domain_id,
            'CertName' => $cert_name,
            'CertId' => $cert_id,
            'CertType' => 'ucdn',
        ];
        if ($HttpsStatusCn == 'enable') $param['HttpsStatusCn'] = $HttpsStatusCn;
        if ($HttpsStatusAbroad == 'enable') $param['HttpsStatusAbroad'] = $HttpsStatusAbroad;
        if ($HttpsStatusCn != 'enable' && $HttpsStatusAbroad != 'enable') $param['HttpsStatusCn'] = 'enable';
        try {
            $data = $this->client->request('UpdateUcdnDomainHttpsConfigV2', $param);
        } catch (Exception $e) {
            throw new Exception('https加速配置失败 ' . $e->getMessage());
        }
        $this->log('云分发' . $domain_id . '证书配置成功！');
        $info['cert_id'] = $cert_id;
        $info['cert_name'] = $cert_name;
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
