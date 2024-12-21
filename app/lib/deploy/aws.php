<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\AWS as AWSClient;
use Exception;

class aws implements DeployInterface
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
        $client = new AWSClient($this->AccessKeyId, $this->SecretAccessKey, 'iam.amazonaws.com', 'iam','2010-05-08', 'us-east-1');
        $client->requestXml('GET', 'GetUser');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['distribution_id'])) throw new Exception('分配ID不能为空');
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $config['cert_name'] = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        if(isset($info['cert_id']) && isset($info['cert_name']) && $info['cert_name'] == $config['cert_name']){
            $cert_id = $info['cert_id'];
            $this->log('证书已上传：' . $cert_id);
        }else{
            $cert_id = $this->get_cert_id($fullchain, $privatekey);
            $this->log('证书上传成功：' . $cert_id);
            $info['cert_id'] = $cert_id;
            $info['cert_name'] = $config['cert_name'];
            usleep(500000);
        }
        
        $client = new \app\lib\client\AWS($this->AccessKeyId, $this->SecretAccessKey, 'cloudfront.amazonaws.com', 'cloudfront', '2020-05-31', 'us-east-1');
        try{
            $data = $client->requestXmlN('GET', '/distribution/'.$config['distribution_id'].'/config', [], null, true);
        }catch(Exception $e){
            throw new Exception('获取分配信息失败：'.$e->getMessage());
        }
        
        $data['ViewerCertificate']['ACMCertificateArn'] = $cert_id;
        $data['ViewerCertificate']['CloudFrontDefaultCertificate'] = false;
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><DistributionConfig></DistributionConfig>');
        $client->requestXmlN('PUT', '/distribution/'.$config['distribution_id'].'/config', $data, $xml);
        $this->log('分配ID: ' . $config['distribution_id'] . ' 证书部署成功！');
    }

    private function get_cert_id($fullchain, $privatekey)
    {
        $cert = explode('-----END CERTIFICATE-----', $fullchain)[0] . '-----END CERTIFICATE-----';
        $param = [
            'Certificate' => base64_encode($cert),
            'PrivateKey' => base64_encode($privatekey),
        ];
        
        $client = new \app\lib\client\AWS($this->AccessKeyId, $this->SecretAccessKey, 'acm.us-east-1.amazonaws.com', 'acm', '', 'us-east-1');
        try{
            $data = $client->request('POST', 'CertificateManager.ImportCertificate', $param);
            $cert_id = $data['CertificateArn'];
        }catch(Exception $e){
            throw new Exception('上传证书失败：'.$e->getMessage());
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
