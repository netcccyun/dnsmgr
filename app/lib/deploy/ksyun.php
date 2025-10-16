<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\Ksyun as KsyunClient;
use Exception;

class ksyun implements DeployInterface
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
        $client = new KsyunClient($this->AccessKeyId, $this->SecretAccessKey, 'cdn.api.ksyun.com', 'cdn', 'cn-shanghai-2', $this->proxy);
        $client->request('GET', 'GetCertificates', '2016-09-01', '/2016-09-01/cert/GetCertificates');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $this->deploy_cdn($fullchain, $privatekey, $config, $info);
    }

    public function deploy_cdn($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $config['cert_name'] = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];
        $domains = explode(',', $config['domain']);

        $client = new KsyunClient($this->AccessKeyId, $this->SecretAccessKey, 'cdn.api.ksyun.com', 'cdn', 'cn-shanghai-2', $this->proxy);
        $param = [
            'PageSize' => 100,
            'PageNumber' => 1,
        ];
        $domain_ids = [];
        $result = $client->request('GET', 'GetCdnDomains', '2019-06-01', '/2019-06-01/domain/GetCdnDomains', $param);
        foreach ($result['Domains'] as $row) {
            if (in_array($row['DomainName'], $domains)) {
                $domain_ids[] = $row['DomainId'];
            }
        }
        if (count($domain_ids) == 0) throw new Exception('未找到对应的CDN域名');
        $param = [
            'Enable' => 'on',
            'DomainIds' => implode(',', $domain_ids),
            'CertificateName' => $config['cert_name'],
            'ServerCertificate' => $fullchain,
            'PrivateKey' => $privatekey,
        ];
        $result = $client->request('POST', 'ConfigCertificate', '2016-09-01', '/2016-09-01/cert/ConfigCertificate', $param);
        $this->log('CDN证书部署成功，证书ID：' . $result['CertificateId']);
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
