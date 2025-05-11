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
        $client = new AWSClient($this->AccessKeyId, $this->SecretAccessKey, 'iam.amazonaws.com', 'iam', '2010-05-08', 'us-east-1', $this->proxy);
        $client->requestXml('GET', 'GetUser');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if ($config['product'] == 'acm') {
            if (empty($config['acm_arn'])) throw new Exception('ACM ARN不能为空');
            $this->get_cert_id($fullchain, $privatekey, $config['acm_arn'], true);
        } else {
            $this->deploy_cloudfront($fullchain, $privatekey, $config, $info);
        }
    }

    private function deploy_cloudfront($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['distribution_id'])) throw new Exception('分配ID不能为空');
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');

        $cert_id = isset($info['cert_id']) ? $info['cert_id'] : null;
        $cert_id = $this->get_cert_id($fullchain, $privatekey, $cert_id, $config['cert_name']);
        usleep(500000);

        $client = new AWSClient($this->AccessKeyId, $this->SecretAccessKey, 'cloudfront.amazonaws.com', 'cloudfront', '2020-05-31', 'us-east-1', $this->proxy);
        try {
            $data = $client->requestXmlN('GET', '/distribution/' . $config['distribution_id'] . '/config', [], null, true);
        } catch (Exception $e) {
            throw new Exception('获取分配信息失败：' . $e->getMessage());
        }

        $data['ViewerCertificate']['ACMCertificateArn'] = $cert_id;
        $data['ViewerCertificate']['CloudFrontDefaultCertificate'] = 'false';
        unset($data['ViewerCertificate']['Certificate']);
        unset($data['ViewerCertificate']['CertificateSource']);

        $xml = new \SimpleXMLElement('<DistributionConfig xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/"></DistributionConfig>');
        $client->requestXmlN('PUT', '/distribution/' . $config['distribution_id'] . '/config', $data, $xml);
        $this->log('分配ID: ' . $config['distribution_id'] . ' 证书部署成功！');
    }

    private function get_cert_id($fullchain, $privatekey, $cert_id = null, $acm = false)
    {
        if ($acm === true && $cert_id == null) {
            throw new Exception('ACM ARN不能为空');
        }

        $certificates = explode('-----END CERTIFICATE-----', $fullchain);
        $cert = $certificates[0] . '-----END CERTIFICATE-----';

        $client = new AWSClient($this->AccessKeyId, $this->SecretAccessKey, 'acm.us-east-1.amazonaws.com', 'acm', '', 'us-east-1', $this->proxy);

        if (!empty($cert_id)) {
            try {
                $data = $client->request('POST', 'CertificateManager.GetCertificate', [
                    'CertificateArn' => $cert_id
                ]);
                // 如果成功获取证书信息，说明证书存在，直接返回cert_id
                if (isset($data['Certificate']) && trim($data['Certificate']) == trim($cert)) {
                    $this->log('证书已是最新，ACM ARN：' . $cert_id);
                    return $cert_id;
                } else {
                    $this->log('证书已过期或被删除，准备更新或者重新上传');
                }
            } catch (Exception $e) {
                if ($acm === true) {
                    throw new Exception('获取证书信息失败，请检查ACM ARN是否正确：' . $e->getMessage());
                }
                $this->log('证书已被删除：' . $cert_id. '，准备重新上传');
            }
        }

        $certificateChain = '';
        if (count($certificates) > 1) {
            // 从第二个证书开始，重新拼接中间证书链
            for ($i = 1; $i < count($certificates); $i++) {
                if (trim($certificates[$i]) !== '') { // 忽略空字符串（可能由末尾分割产生）
                    $certificateChain .= $certificates[$i] . '-----END CERTIFICATE-----';
                }
            }
        }

        $param = [
            'Certificate' => base64_encode($cert),
            'PrivateKey' => base64_encode($privatekey),
        ];

        // 如果有中间证书链，则添加到参数中
        if (!empty($certificateChain)) {
            $param['CertificateChain'] = base64_encode($certificateChain);
        }

        // 如果是ACM，则添加ARN参数，用于更新证书
        if ($acm === true) {
            $param['CertificateArn'] = $cert_id;
        }

        $client = new AWSClient($this->AccessKeyId, $this->SecretAccessKey, 'acm.us-east-1.amazonaws.com', 'acm', '', 'us-east-1', $this->proxy);
        try {
            $data = $client->request('POST', 'CertificateManager.ImportCertificate', $param);
            $cert_id = $data['CertificateArn'];
        } catch (Exception $e) {
            throw new Exception('上传证书失败：' . $e->getMessage());
        }

        $this->log('证书上传成功：' . $cert_id);

        $info['cert_id'] = $cert_id;

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
