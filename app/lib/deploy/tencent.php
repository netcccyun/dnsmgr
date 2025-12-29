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
        if ($config['product'] == 'update') {
            return $this->update_cert($fullchain, $privatekey, $config);
        }
        $cert_id = $this->get_cert_id($fullchain, $privatekey);
        if (!$cert_id) throw new Exception('证书ID获取失败');
        $info['cert_id'] = $cert_id;
        if ($config['product'] == 'cos') {
            if (empty($config['regionid'])) throw new Exception('所属地域ID不能为空');
            if (empty($config['cos_bucket'])) throw new Exception('存储桶名称不能为空');
            if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
            $instance_id = $config['regionid'] . '|' . $config['cos_bucket'] . '|' . $config['domain'];
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
        } elseif ($config['product'] == 'ddos') {
            if (empty($config['lighthouse_id'])) throw new Exception('实例ID不能为空');
            if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');
            $instance_id = $config['lighthouse_id'] . '|' . $config['domain'] . '|443';
        } elseif ($config['product'] == 'clb') {
            return $this->deploy_clb($cert_id, $config);
        } elseif ($config['product'] == 'scf') {
            return $this->deploy_scf($cert_id, $config);
        } elseif ($config['product'] == 'teo' && isset($config['site_id'])) {
            return $this->deploy_teo($cert_id, $config);
        } elseif ($config['product'] == 'upload') {
            return;
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

        $param = [
            'CertificateIds' => [$data['CertificateId']],
            'SwitchStatus' => 1,
        ];
        $this->client->request('ModifyCertificatesExpiringNotificationSwitch', $param);

        return $data['CertificateId'];
    }

    private function deploy_common($product, $cert_id, $instance_id)
    {
        if (in_array($product, ['cdn', 'waf', 'teo', 'ddos', 'live', 'vod']) && strpos($instance_id, ',') !== false) {
            $instance_ids = explode(',', $instance_id);
        } else {
            $instance_ids = [$instance_id];
        }
        if ($product == 'cdn') {
            $instance_ids = array_map(function ($id) {
                return $id . '|on';
            }, $instance_ids);
        }
        $param = [
            'CertificateId' => $cert_id,
            'InstanceIdList' => $instance_ids,
            'ResourceType' => $product,
        ];
        if ($product == 'live') $param['Status'] = 1;
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

    private function deploy_teo($cert_id, $config)
    {
        if (empty($config['site_id'])) throw new Exception('站点ID不能为空');
        if (empty($config['domain'])) throw new Exception('绑定的域名不能为空');

        $endpoint = isset($config['site_type']) && $config['site_type'] == 'intl' ? 'teo.intl.tencentcloudapi.com' : 'teo.tencentcloudapi.com';
        $client = new TencentCloud($this->SecretId, $this->SecretKey, $endpoint, 'teo', '2022-09-01', null, $this->proxy);
        $hosts = explode(',', $config['domain']);
        $param = [
            'ZoneId' => $config['site_id'],
            'Hosts' => $hosts,
            'Mode' => 'sslcert',
            'ServerCertInfo' => [[
                'CertId' => $cert_id
            ]]
        ];
        $data = $client->request('ModifyHostsCertificate', $param);
        $this->log('边缘安全加速域名 ' . $config['domain'] . ' 部署证书成功！');
    }

    private function update_cert($fullchain, $privatekey, $config)
    {
        if (empty($config['cert_id'])) throw new Exception('证书ID不能为空');

        $param = [
            'CertificateIds' => [$config['cert_id']],
            'IsCache' => 1,
        ];
        try {
            $data = $this->client->request('CreateCertificateBindResourceSyncTask', $param);
            if (empty($data['CertTaskIds'])) throw new Exception('返回任务ID为空');
        } catch (Exception $e) {
            throw new Exception('创建关联云资源查询任务失败：' . $e->getMessage());
        }
        $task_id = $data['CertTaskIds'][0]['TaskId'];
        $this->log('创建关联云资源查询任务成功 TaskId=' . $task_id);

        $retry = 0;
        $resource_result = null;
        while ($retry++ < 30) {
            sleep(2);
            $param = [
                'TaskIds' => [$task_id],
            ];
            try {
                $data = $this->client->request('DescribeCertificateBindResourceTaskResult', $param);
                if (empty($data['SyncTaskBindResourceResult'])) throw new Exception('返回结果为空');
            } catch (Exception $e) {
                throw new Exception('查询关联云资源任务结果失败：' . $e->getMessage());
            }
            $taskResult = $data['SyncTaskBindResourceResult'][0];
            if ($taskResult['Status'] == 1) {
                $resource_result = $taskResult['BindResourceResult'];
                break;
            } elseif ($taskResult['Status'] == 2) {
                throw new Exception('关联云资源查询任务执行失败：' . isset($taskResult['Error']) ? $taskResult['Error']['Message'] : '未知错误');
            }
        };
        if (!$resource_result) {
            throw new Exception('关联云资源查询任务超时未完成，请稍后重试');
        }

        $resourceTypes = [];
        $resourceTypesRegions = [];
        foreach ($resource_result as $res) {
            if ($res['ResourceType'] != 'clb') continue;
            $totalCount = 0;
            $regions = [];
            foreach ($res['BindResourceRegionResult'] as $regionRes) {
                if ($regionRes['TotalCount'] > 0) {
                    $totalCount += $regionRes['TotalCount'];
                    if (!empty($regionRes['Region'])) {
                        $regions[] = $regionRes['Region'];
                    }
                }
            }
            if ($totalCount > 0) {
                $resourceTypes[] = $res['ResourceType'];
                if (!empty($regions)) {
                    $resourceTypesRegions[] = [
                        'ResourceType' => $res['ResourceType'],
                        'Regions' => $regions,
                    ];
                }
            }
        }

        $param = [
            'OldCertificateId' => $config['cert_id'],
            'CertificatePublicKey' => $fullchain,
            'CertificatePrivateKey' => $privatekey,
            'ResourceTypes' => $resourceTypes,
            'ResourceTypesRegions' => $resourceTypesRegions,
        ];
        $retry = 0;
        while ($retry++ < 10) {
            try {
                $data = $this->client->request('UploadUpdateCertificateInstance', $param);
            } catch (Exception $e) {
                throw new Exception('更新证书内容失败：' . $e->getMessage());
            }
            if ($data['DeployStatus'] == 1) {
                break;
            }
            sleep(1);
        }
        $this->log('更新证书内容成功，可能需要一些时间完成各资源的证书更新部署');
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
