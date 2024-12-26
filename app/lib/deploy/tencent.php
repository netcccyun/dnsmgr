<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\TencentCloud;
use Exception;

class tencent implements DeployInterface
{
    private $logger;
    private $SecretId;
    private $SecretKey;
    private TencentCloud $client;
    private $proxy;

    public function __construct($config)
    {
        $this->SecretId = $config['SecretId'];
        $this->SecretKey = $config['SecretKey'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
        $this->client = new TencentCloud($this->SecretId, $this->SecretKey, 'ssl.tencentcloudapi.com', 'ssl', '2019-12-05', null, $this->proxy);
    }

    public function check()
    {
        if (empty($this->SecretId) || empty($this->SecretKey)) throw new Exception('必填参数不能为空');
        $this->client->request('DescribeCertificates', []);
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $cert_id = $this->get_cert_id($fullchain, $privatekey);
        if (!$cert_id) throw new Exception('证书ID获取失败');
        if ($config['product'] == 'cos') {
            if (empty($config['regionid'])) throw new Exception('所属地域ID不能为空');
            if (empty($config['cos_bucket'])) throw new Exception('存储桶名称不能为空');
            if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
            $instance_id = $config['regionid'] . '#' . $config['cos_bucket'] . '#' . $config['domain'];
            $this->client = new TencentCloud($this->SecretId, $this->SecretKey, 'ssl.tencentcloudapi.com', 'ssl', '2019-12-05', $config['regionid'], $this->proxy);
        } elseif ($config['product'] == 'tke') {
            if (empty($config['regionid'])) throw new Exception('所属地域ID不能为空');
            if (empty($config['tke_cluster_id'])) throw new Exception('集群ID不能为空');
            if (empty($config['tke_namespace'])) throw new Exception('命名空间不能为空');
            if (empty($config['tke_secret'])) throw new Exception('secret名称不能为空');
            $instance_id = $config['tke_cluster_id'] . '|' . $config['tke_namespace'] . '|' . $config['tke_secret'];
            $this->client = new TencentCloud($this->SecretId, $this->SecretKey, 'ssl.tencentcloudapi.com', 'ssl', '2019-12-05', $config['regionid'], $this->proxy);
        } elseif ($config['product'] == 'lighthouse') {
            if (empty($config['regionid'])) throw new Exception('所属地域ID不能为空');
            if (empty($config['lighthouse_id'])) throw new Exception('实例ID不能为空');
            if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
            $instance_id = $config['regionid'] . '|' . $config['lighthouse_id'] . '|' . $config['domain'];
            $this->client = new TencentCloud($this->SecretId, $this->SecretKey, 'ssl.tencentcloudapi.com', 'ssl', '2019-12-05', $config['regionid'], $this->proxy);
        } elseif ($config['product'] == 'clb') {
            return $this->deploy_clb($cert_id, $config);
        } elseif ($config['product'] == 'scf') {
            return $this->deploy_scf($cert_id, $config);
        } else {
            if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
            if ($config['product'] == 'waf') {
                $this->client = new TencentCloud($this->SecretId, $this->SecretKey, 'ssl.tencentcloudapi.com', 'ssl', '2019-12-05', $config['region'], $this->proxy);
            } elseif (in_array($config['product'], ['tse', 'scf'])) {
                if (empty($config['regionid'])) throw new Exception('所属地域ID不能为空');
                $this->client = new TencentCloud($this->SecretId, $this->SecretKey, 'ssl.tencentcloudapi.com', 'ssl', '2019-12-05', $config['regionid'], $this->proxy);
            }
            $instance_id = $config['domain'];
        }
        try {
            $record_id = $this->deploy_common($config['product'], $cert_id, $instance_id);
            $info['cert_id'] = $cert_id;
            $info['record_id'] = $record_id;
        } catch (Exception $e) {
            if (isset($info['record_id'])) {
                if ($this->deploy_query($info['record_id'])) {
                    $this->log(strtoupper($config['product']) . '实例 ' . $instance_id . ' 已部署证书，无需重复部署');
                    return;
                }
            }
            throw $e;
        }
    }

    private function get_cert_id($fullchain, $privatekey)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $param = [
            'CertificatePublicKey' => $fullchain,
            'CertificatePrivateKey' => $privatekey,
            'CertificateType' => 'SVR',
            'Alias' => $cert_name,
            'Repeatable' => false,
        ];
        try {
            $data = $this->client->request('UploadCertificate', $param);
        } catch (Exception $e) {
            throw new Exception('上传证书失败：' . $e->getMessage());
        }
        $this->log('上传证书成功 CertificateId=' . $data['CertificateId']);
        usleep(300000);
        return $data['CertificateId'];
    }

    private function deploy_common($product, $cert_id, $instance_id)
    {
        if (in_array($product, ['cdn', 'waf', 'teo', 'ddos', 'live', 'vod']) && strpos($instance_id, ',') !== false) {
            $instance_ids = explode(',', $instance_id);
        } else {
            $instance_ids = [$instance_id];
        }
        $param = [
            'CertificateId' => $cert_id,
            'InstanceIdList' => $instance_ids,
            'ResourceType' => $product,
        ];
        $data = $this->client->request('DeployCertificateInstance', $param);
        $this->log(json_encode($data));
        $this->log(strtoupper($product) . '实例 ' . $instance_id . ' 部署证书成功！');
        return $data['DeployRecordId'];
    }

    private function deploy_query($record_id)
    {
        $param = [
            'DeployRecordId' => strval($record_id),
        ];
        try {
            $data = $this->client->request('DescribeHostDeployRecordDetail', $param);
            if (isset($data['SuccessTotalCount']) && $data['SuccessTotalCount'] >= 1 || isset($data['RunningTotalCount']) && $data['RunningTotalCount'] >= 1) {
                return true;
            }
            if (isset($data['FailedTotalCount']) && $data['FailedTotalCount'] >= 1 && !empty($data['DeployRecordDetailList'])) {
                $errmsg = $data['DeployRecordDetailList'][0]['ErrorMsg'];
                if (strpos($errmsg, '\u')) {
                    $errmsg = json_decode($errmsg);
                }
                $this->log('证书部署失败原因：' . $errmsg);
            }
        } catch (Exception $e) {
            $this->log('查询证书部署记录失败：' . $e->getMessage());
        }
        return false;
    }

    private function deploy_clb($cert_id, $config)
    {
        if (empty($config['regionid'])) throw new Exception('所属地域ID不能为空');
        if (empty($config['clb_id'])) throw new Exception('负载均衡ID不能为空');
        $sni_switch = !empty($config['clb_domain']) ? 1 : 0;

        $client = new TencentCloud($this->SecretId, $this->SecretKey, 'clb.tencentcloudapi.com', 'clb', '2018-03-17', $config['regionid'], $this->proxy);
        $param = [
            'LoadBalancerId' => $config['clb_id'],
            'Protocol' => 'HTTPS',
        ];
        if (!empty($config['clb_listener_id'])) {
            $param['ListenerIds'] = [$config['clb_listener_id']];
        }
        try {
            $data = $client->request('DescribeListeners', $param);
        } catch (Exception $e) {
            throw new Exception('获取监听器列表失败：' . $e->getMessage());
        }
        if (!isset($data['TotalCount']) || $data['TotalCount'] == 0) throw new Exception('负载均衡:' . $config['clb_id'] . '监听器列表为空');
        $count = 0;
        foreach ($data['Listeners'] as $listener) {
            if ($listener['SniSwitch'] == $sni_switch) {
                if ($sni_switch == 1) {
                    foreach ($listener['Rules'] as $rule) {
                        if ($rule['Domain'] == $config['clb_domain']) {
                            if (isset($rule['Certificate']['CertId']) && $cert_id == $rule['Certificate']['CertId']) {
                                $this->log('负载均衡监听器 ' . $listener['ListenerId'] . ' 域名 ' . $rule['Domain'] . ' 已部署证书，无需重复部署');
                            } else {
                                $param = [
                                    'LoadBalancerId' => $config['clb_id'],
                                    'ListenerId' => $listener['ListenerId'],
                                    'Domain' => $rule['Domain'],
                                    'Certificate' => [
                                        'SSLMode' => 'UNIDIRECTIONAL',
                                        'CertId' => $cert_id,
                                    ],
                                ];
                                $client->request('ModifyDomainAttributes', $param);
                                $this->log('负载均衡监听器 ' . $listener['ListenerId'] . ' 域名 ' . $rule['Domain'] . ' 部署证书成功！');
                            }
                            $count++;
                        }
                    }
                } else {
                    if (isset($listener['Certificate']['CertId']) && $cert_id == $listener['Certificate']['CertId']) {
                        $this->log('负载均衡监听器 ' . $listener['ListenerId'] . ' 已部署证书，无需重复部署');
                    } else {
                        $param = [
                            'LoadBalancerId' => $config['clb_id'],
                            'ListenerId' => $listener['ListenerId'],
                            'Certificate' => [
                                'SSLMode' => 'UNIDIRECTIONAL',
                                'CertId' => $cert_id,
                            ],
                        ];
                        $client->request('ModifyListener', $param);
                        $this->log('负载均衡监听器 ' . $listener['ListenerId'] . ' 部署证书成功！');
                    }
                    $count++;
                }
            }
        }
        if ($count == 0) throw new Exception('没有找到要更新证书的监听器');
    }

    private function deploy_scf($cert_id, $config)
    {
        if (empty($config['regionid'])) throw new Exception('所属地域ID不能为空');
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');

        $client = new TencentCloud($this->SecretId, $this->SecretKey, 'scf.tencentcloudapi.com', 'scf', '2018-04-16', $config['regionid'], $this->proxy);
        $param = [
            'Domain' => $config['domain'],
        ];
        try {
            $data = $client->request('GetCustomDomain', $param);
        } catch (Exception $e) {
            throw new Exception('获取云函数自定义域名失败：' . $e->getMessage());
        }

        if (isset($data['CertConfig']['CertificateId']) && $data['CertConfig']['CertificateId'] == $cert_id) {
            $this->log('云函数自定义域名 ' . $config['domain'] . ' 已部署证书，无需重复部署');
            return;
        }
        $data['CertConfig']['CertificateId'] = $cert_id;
        if ($data['Protocol'] == 'HTTP') $data['Protocol'] = 'HTTP&HTTPS';

        $param = [
            'Domain' => $config['domain'],
            'Protocol' => $data['Protocol'],
            'CertConfig' => $data['CertConfig'],
        ];
        $data = $client->request('UpdateCustomDomain', $param);
        $this->log('云函数自定义域名 ' . $config['domain'] . ' 部署证书成功！');
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
