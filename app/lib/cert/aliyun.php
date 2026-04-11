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
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $this->Endpoint, $this->Version, $proxy);
        $this->config = $config;
    }

    public function register()
    {
        if (empty($this->AccessKeyId) || empty($this->AccessKeySecret)) throw new Exception('必填参数不能为空');
        $param = ['Action' => 'ListInstances'];
        $this->request($param, true);
        return true;
    }

    public function buyCert($domainList, &$order)
    {
        $param = ['Action' => 'GetInstanceSummary', 'InstanceType' => 'TEST'];
        $data = $this->request($param, true);
        if (!isset($data['InactiveCount']) || $data['InactiveCount'] == 0) throw new Exception('没有待使用的测试证书实例，请先购买测试证书');
        $this->log('实例总个数:' . $data['TotalCount'] . ',实例待使用总数:' . $data['InactiveCount']);
    }

    public function createOrder($domainList, &$order, $keytype, $keysize)
    {
        if (empty($domainList)) throw new Exception('域名列表不能为空');
        $domain = $domainList[0];
        $param = [
            'Action' => 'ListInstances',
            'Status' => 'inactive',
            'InstanceType' => 'TEST',
        ];
        $data = $this->request($param, true);
        if (empty($data['InstanceList'])) throw new Exception('待使用的测试证书实例列表为空');
        $instanceId = $data['InstanceList'][0]['InstanceId'];

        $param = [
            'Action' => 'ListContact',
        ];
        $data = $this->request($param, true);
        if (empty($data['ContactList'])) throw new Exception('联系人列表为空，请先添加联系人');
        $contactId = $data['ContactList'][0]['ContactId'];

        if ($keytype == 'ECC') $KeyAlgorithm = 'ECC_256';
        else if ($keysize == '3072') $KeyAlgorithm = 'RSA_3072';
        else $KeyAlgorithm = 'RSA_2048';
        $param = [
            'Action' => 'UpdateInstance',
            'InstanceId' => $instanceId,
            'Domain' => $domain,
            'KeyAlgorithm' => $KeyAlgorithm,
            'AutoReissue' => 'disable',
            'ContactIdList.1' => $contactId,
            'ValidateType' => 'DNS'
        ];
        try {
            $this->request($param);
        } catch (Exception $e) {
            throw new Exception('更新证书实例失败：' . $e->getMessage());
        }

        $param = [
            'Action' => 'ApplyCertificate',
            'InstanceId' => $instanceId
        ];
        try {
            $this->request($param);
        } catch (Exception $e) {
            throw new Exception('申请证书失败：' . $e->getMessage());
        }

        sleep(1);

        $status = '';
        do {
            $param = [
                'Action' => 'GetTaskAttribute',
                'TaskId' => $instanceId
            ];
            try {
                $data = $this->request($param, true);
                $status = $data['TaskStatus'];
            } catch (Exception $e) {
                throw new Exception('申请证书提交结果查询失败：' . $e->getMessage());
            }
            if ($status == 'processing') {
                sleep(1);
            } elseif ($status == 'failed') {
                throw new Exception('申请证书失败：' . $data['TaskMessage']);
            } else {
                break;
            }
        } while ($status == 'processing');


        $param = [
            'Action' => 'GetInstanceDetail',
            'InstanceId' => $instanceId
        ];
        try {
            $data = $this->request($param, true);
        } catch (Exception $e) {
            throw new Exception('获取实例详情失败：' . $e->getMessage());
        }

        $order['InstanceId'] = $instanceId;

        $dnsList = [];
        if (!empty($data['DomainValidationList'])) {
            foreach ($data['DomainValidationList'] as $opts) {
                $mainDomain = getMainDomain($opts['Domain']);
                $name = substr($opts['ValidationKey'] . '.' . $opts['RootDomain'], 0, - (strlen($mainDomain) + 1));
                $dnsList[$mainDomain][] = ['name' => $name, 'type' => $opts['ValidationType'], 'value' => $opts['ValidationValue']];
            }
        }

        return $dnsList;
    }

    public function authOrder($domainList, $order) {}

    public function getAuthStatus($domainList, $order)
    {
        $param = [
            'Action' => 'GetInstanceDetail',
            'InstanceId' => $order['InstanceId'],
        ];
        $data = $this->request($param, true);
        if ($data['Status'] == 'normal') {
            return true;
        } elseif ($data['Status'] == 'closed') {
            throw new Exception('证书审核失败');
        } else {
            return false;
        }
    }

    public function finalizeOrder($domainList, $order, $keytype, $keysize)
    {
        $param = [
            'Action' => 'GetInstanceDetail',
            'InstanceId' => $order['InstanceId'],
        ];
        $data = $this->request($param, true);
        if (empty($data['CertificateId'])) throw new Exception('证书ID不存在');

        $param = [
            'Action' => 'GetUserCertificateDetail',
            'CertId' => $data['CertificateId'],
        ];
        $data = $this->request($param, true);
        $fullchain = $data['Cert'];
        $private_key = $data['Key'];
        if (empty($fullchain) || empty($private_key)) throw new Exception('证书内容获取失败');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        return ['private_key' => $private_key, 'fullchain' => $fullchain, 'issuer' => $certInfo['issuer']['CN'], 'subject' => $certInfo['subject']['CN'], 'validFrom' => $certInfo['validFrom_time_t'], 'validTo' => $certInfo['validTo_time_t']];
    }

    public function revoke($order, $pem)
    {
        $param = [
            'Action' => 'RevokeCertificate',
            'InstanceId' => $order['InstanceId'],
        ];
        $this->request($param);
    }

    public function cancel($order)
    {
        $param = [
            'Action' => 'GetInstanceDetail',
            'InstanceId' => $order['InstanceId'],
        ];
        $data = $this->request($param, true);
        if ($data['Status'] == 'pending') {
            $param = [
                'Action' => 'CancelPendingCertificate',
                'InstanceId' => $order['InstanceId'],
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
