<?php

namespace app\lib\cert;

use app\lib\CertInterface;
use app\lib\client\Aliyun as AliyunClient;
use Exception;

class aliyun implements CertInterface
{
    private $AccessKeyId;
    private $AccessKeySecret;
    private $Endpoint = 'cas.aliyuncs.com'; //API接入域名
    private $Version = '2020-04-07'; //API版本号
    private $config;
    private $logger;
    private AliyunClient $client;

    public function __construct($config, $ext = null)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->AccessKeySecret = $config['AccessKeySecret'];
        $this->client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $this->Endpoint, $this->Version);
        $this->config = $config;
    }

    public function register()
    {
        if (empty($this->AccessKeyId) || empty($this->AccessKeySecret) || empty($this->config['username']) || empty($this->config['phone']) || empty($this->config['email'])) throw new Exception('必填参数不能为空');
        $param = ['Action' => 'ListUserCertificateOrder'];
        $this->request($param, true);
        return true;
    }

    public function buyCert($domainList, &$order)
    {
        $param = ['Action' => 'DescribePackageState', 'ProductCode' => 'digicert-free-1-free'];
        $data = $this->request($param, true);
        if (!isset($data['TotalCount']) || $data['TotalCount'] == 0) throw new Exception('没有可用的免费证书资源包');
        $this->log('证书资源包总数量:' . $data['TotalCount'] . ',已使用数量:' . $data['UsedCount']);
    }

    public function createOrder($domainList, &$order, $keytype, $keysize)
    {
        if (empty($domainList)) throw new Exception('域名列表不能为空');
        $domain = $domainList[0];
        $param = [
            'Action' => 'CreateCertificateRequest',
            'ProductCode' => 'digicert-free-1-free',
            'Username' => $this->config['username'],
            'Phone' => $this->config['phone'],
            'Email' => $this->config['email'],
            'Domain' => $domain,
            'ValidateType' => 'DNS'
        ];
        $data = $this->request($param, true);
        if (empty($data['OrderId'])) throw new Exception('证书申请失败，OrderId为空');
        $order['OrderId'] = $data['OrderId'];

        sleep(3);

        $param = [
            'Action' => 'DescribeCertificateState',
            'OrderId' => $order['OrderId'],
        ];
        $data = $this->request($param, true);

        $dnsList = [];
        if ($data['Type'] == 'domain_verify') {
            $mainDomain = getMainDomain($domain);
            $name = str_replace('.' . $mainDomain, '', $data['RecordDomain']);
            $dnsList[$mainDomain][] = ['name' => $name, 'type' => $data['RecordType'], 'value' => $data['RecordValue']];
        }

        return $dnsList;
    }

    public function authOrder($domainList, $order) {}

    public function getAuthStatus($domainList, $order)
    {
        $param = [
            'Action' => 'DescribeCertificateState',
            'OrderId' => $order['OrderId'],
        ];
        $data = $this->request($param, true);
        if ($data['Type'] == 'certificate') {
            return true;
        } elseif ($data['Type'] == 'verify_fail') {
            throw new Exception('证书审核失败');
        } else {
            return false;
        }
    }

    public function finalizeOrder($domainList, $order, $keytype, $keysize)
    {
        $param = [
            'Action' => 'DescribeCertificateState',
            'OrderId' => $order['OrderId'],
        ];
        $data = $this->request($param, true);
        $fullchain = $data['Certificate'];
        $private_key = $data['PrivateKey'];
        if (empty($fullchain) || empty($private_key)) throw new Exception('证书内容获取失败');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        return ['private_key' => $private_key, 'fullchain' => $fullchain, 'issuer' => $certInfo['issuer']['CN'], 'subject' => $certInfo['subject']['CN'], 'validFrom' => $certInfo['validFrom_time_t'], 'validTo' => $certInfo['validTo_time_t']];
    }

    public function revoke($order, $pem)
    {
        $param = [
            'Action' => 'CancelCertificateForPackageRequest',
            'OrderId' => $order['OrderId'],
        ];
        $this->request($param);
    }

    public function cancel($order)
    {
        $param = [
            'Action' => 'DescribeCertificateState',
            'OrderId' => $order['OrderId'],
        ];
        $data = $this->request($param, true);
        if ($data['Type'] == 'domain_verify' || $data['Type'] == 'process') {
            $param = [
                'Action' => 'CancelOrderRequest',
                'OrderId' => $order['OrderId'],
            ];
            $this->request($param);
            usleep(500000);
        }
        if ($data['Type'] == 'domain_verify' || $data['Type'] == 'process' || $data['Type'] == 'payed' || $data['Type'] == 'verify_fail') {
            $param = [
                'Action' => 'DeleteCertificateRequest',
                'OrderId' => $order['OrderId'],
            ];
            $this->request($param);
        }
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

    private function request($param, $returnData = false)
    {
        $this->log('Request:' . json_encode($param, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $result = $this->client->request($param);
        $response = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!strpos($response, '"Type":"certificate"')) {
            $this->log('Response:' . $response);
        }
        return $returnData ? $result : true;
    }
}
