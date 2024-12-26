<?php

namespace app\lib\cert;

use app\lib\CertInterface;
use app\lib\client\Volcengine;
use Exception;

class huoshan implements CertInterface
{
    private $AccessKeyId;
    private $SecretAccessKey;
    private $endpoint = "open.volcengineapi.com";
    private $service = "certificate_service";
    private $version = "2021-06-01";
    private $region = "cn-north-1";
    private $logger;
    private Volcengine $client;

    public function __construct($config = null, $ext = null)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->SecretAccessKey = $config['SecretAccessKey'];
        $proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new Volcengine($this->AccessKeyId, $this->SecretAccessKey, $this->endpoint, $this->service, $this->version, $this->region, $proxy);
    }

    public function register()
    {
        if (empty($this->AccessKeyId) || empty($this->SecretAccessKey)) throw new Exception('必填参数不能为空');
        $this->request('GET', 'CertificateGetInstance', ['limit'=>1,'offset'=>0]);
        return true;
    }

    public function buyCert($domainList, &$order)
    {
        $data = $this->request('GET', 'CertificateGetOrganization');
        if(empty($data['content'])) throw new Exception('请先添加信息模板');
        $order['organization_id'] = $data['content'][0]['id'];
    }

    public function createOrder($domainList, &$order, $keytype, $keysize)
    {
        if (empty($domainList)) throw new Exception('域名列表不能为空');
        $domain = $domainList[0];
        $param = [
            'plan' => 'digicert_free_standard_dv',
            'common_name' => $domain,
            'organization_id' => $order['organization_id'],
            'key_alg' => strtolower($keytype),
            'validation_type' => 'dns_txt',
        ];
        $instance_id = $this->request('POST', 'QuickApplyCertificate', $param);
        if(empty($instance_id)) throw new Exception('证书申请失败，证书实例ID为空');
        $order['instance_id'] = $instance_id;

        sleep(3);

        $param = [
            'instance_id' => $instance_id,
        ];
        $data = $this->request('GET', 'CertificateGetDcvParam', $param);

        $dnsList = [];
        if (!empty($data['domains_to_be_validated'])) {
            $type = $data['validation_type'] == 'dns_cname' ? 'CNAME' : 'TXT';
            foreach ($data['domains_to_be_validated'] as $opts) {
                $mainDomain = getMainDomain($domain);
                $name = str_replace('.' . $mainDomain, '', $opts['validation_domain']);
                $dnsList[$mainDomain][] = ['name' => $name, 'type' => $type, 'value' => $opts['value']];
            }
        }
        return $dnsList;
    }

    public function authOrder($domainList, $order)
    {
        $query = [
            'instance_id' => $order['instance_id'],
        ];
        $param = [
            'action' => '',
        ];
        $this->request('POST', 'CertificateProgressInstanceOrder', $param, $query);
    }

    public function getAuthStatus($domainList, $order)
    {
        $param = [
            'instance_id' => $order['instance_id'],
        ];
        $data = $this->request('GET', 'CertificateGetInstance', $param);
        if(empty($data['content'])) throw new Exception('证书信息获取失败');
        $data = $data['content'][0];
        if($data['order_status'] == 300 && $data['certificate_exist'] == 1){
            return true;
        }elseif($data['order_status'] == 302){
            throw new Exception('证书申请失败');
        }else{
            return false;
        }
    }

    public function finalizeOrder($domainList, $order, $keytype, $keysize)
    {
        $param = [
            'instance_id' => $order['instance_id'],
        ];
        $data = $this->request('GET', 'CertificateGetInstance', $param);
        if (empty($data['content'])) throw new Exception('证书信息获取失败');
        $data = $data['content'][0];
        if (!isset($data['ssl']['certificate']['chain'])) throw new Exception('证书内容获取失败');

        $fullchain = implode('', $data['ssl']['certificate']['chain']);
        $private_key = $data['ssl']['certificate']['private_key'];

        return ['private_key' => $private_key, 'fullchain' => $fullchain, 'issuer' => $data['issuer'], 'subject' => $data['common_name']['CN'], 'validFrom' => intval($data['certificate_not_before_ms']/1000), 'validTo' => intval($data['certificate_not_after_ms']/1000)];
    }

    public function revoke($order, $pem)
    {
        $query = [
            'instance_id' => $order['instance_id'],
        ];
        $param = [
            'action' => 'revoke',
            'reason' => '关联域名错误',
        ];
        $this->request('POST', 'CertificateProgressInstanceOrder', $param, $query);
    }

    public function cancel($order)
    {
        $query = [
            'instance_id' => $order['instance_id'],
        ];
        $param = [
            'action' => 'cancel',
        ];
        $this->request('POST', 'CertificateProgressInstanceOrder', $param, $query);
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

    private function request($method, $action, $params = [], $query = [])
    {
        $this->log('Action:'.$action.PHP_EOL.'Request:'.json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $result = $this->client->request($method, $action, $params, $query);
        if (is_array($result)) {
            $this->log('Response:'.json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $result;
    }
}
