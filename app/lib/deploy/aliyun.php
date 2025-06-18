<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\client\Aliyun as AliyunClient;
use app\lib\client\AliyunNew as AliyunNewClient;
use app\lib\client\AliyunOSS;
use Exception;

class aliyun implements DeployInterface
{
    private $logger;
    private $AccessKeyId;
    private $AccessKeySecret;
    private $proxy;

    public function __construct($config)
    {
        $this->AccessKeyId = $config['AccessKeyId'];
        $this->AccessKeySecret = $config['AccessKeySecret'];
        $this->proxy = isset($config['proxy']) ? $config['proxy'] == 1 : false;
    }

    public function check()
    {
        if (empty($this->AccessKeyId) || empty($this->AccessKeySecret)) throw new Exception('必填参数不能为空');
        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, 'cas.aliyuncs.com', '2020-04-07', $this->proxy);
        $param = ['Action' => 'ListUserCertificateOrder'];
        $client->request($param);
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if ($config['product'] == 'api') {
            $this->deploy_api($fullchain, $privatekey, $config);
        } elseif ($config['product'] == 'vod') {
            $this->deploy_vod($fullchain, $privatekey, $config);
        } elseif ($config['product'] == 'fc') {
            $this->deploy_fc($fullchain, $privatekey, $config);
        } elseif ($config['product'] == 'fc2') {
            $this->deploy_fc2($fullchain, $privatekey, $config);
        } else {
            [$cert_id, $cert_name] = $this->get_cert_id($fullchain, $privatekey, $config);
            if (!$cert_id) throw new Exception('证书ID获取失败');
            if ($config['product'] == 'cdn') {
                $this->deploy_cdn($cert_id, $cert_name, $config);
            } elseif ($config['product'] == 'dcdn') {
                $this->deploy_dcdn($cert_id, $cert_name, $config);
            } elseif ($config['product'] == 'esa') {
                $this->deploy_esa($cert_id, $config);
            } elseif ($config['product'] == 'oss') {
                $this->deploy_oss($cert_id, $config);
            } elseif ($config['product'] == 'waf') {
                $this->deploy_waf($cert_id, $config);
            } elseif ($config['product'] == 'waf2') {
                $this->deploy_waf2($cert_id, $config);
            } elseif ($config['product'] == 'ddoscoo') {
                $this->deploy_ddoscoo($cert_id, $config);
            } elseif ($config['product'] == 'live') {
                $this->deploy_live($cert_id, $cert_name, $config);
            } elseif ($config['product'] == 'clb') {
                $this->deploy_clb($cert_id, $cert_name, $config);
            } elseif ($config['product'] == 'alb') {
                $this->deploy_alb($cert_id, $config);
            } elseif ($config['product'] == 'nlb') {
                $this->deploy_nlb($cert_id, $config);
            } else {
                throw new Exception('未知的产品类型');
            }
            $info['cert_id'] = $cert_id;
            $info['cert_name'] = $cert_name;
        }
    }

    private function get_cert_id($fullchain, $privatekey)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];
        $serial_no = strtolower($certInfo['serialNumberHex']);

        if ($config['region'] == 'ap-southeast-1') {
            $endpoint = 'cas.ap-southeast-1.aliyuncs.com';

        } else {
            $endpoint = 'cas.aliyuncs.com';
        }

        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2020-04-07', $this->proxy);
        $param = [
            'Action' => 'ListUserCertificateOrder',
            'Keyword' => $certInfo['subject']['CN'],
            'OrderType' => 'CERT',
        ];
        try {
            $data = $client->request($param);
        } catch (Exception $e) {
            throw new Exception('查询证书列表失败：' . $e->getMessage());
        }
        $cert_id = null;
        if ($data['TotalCount'] > 0 && !empty($data['CertificateOrderList'])) {
            foreach ($data['CertificateOrderList'] as $cert) {
                if (strtolower($cert['SerialNo']) == $serial_no) {
                    $cert_id = $cert['CertificateId'];
                    $cert_name = $cert['Name'];
                    break;
                }
            }
        }
        if ($cert_id) {
            $this->log('找到已上传的证书 CertId=' . $cert_id);
            return [$cert_id, $cert_name];
        }

        $param = [
            'Action' => 'UploadUserCertificate',
            'Name' => $cert_name,
            'Cert' => $fullchain,
            'Key' => $privatekey,
        ];
        try {
            $data = $client->request($param);
        } catch (Exception $e) {
            throw new Exception('上传证书失败：' . $e->getMessage());
        }
        $this->log('证书上传成功！CertId=' . $data['CertId']);
        usleep(500000);
        return [$data['CertId'], $cert_name];
    }

    private function deploy_cdn($cert_id, $cert_name, $config)
    {
        $domain = $config['domain'];
        if (empty($domain)) throw new Exception('CDN绑定域名不能为空');
        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, 'cdn.aliyuncs.com', '2018-05-10', $this->proxy);
        $param = [
            'Action' => 'SetCdnDomainSSLCertificate',
            'DomainName' => $domain,
            'CertName' => $cert_name,
            'CertType' => 'cas',
            'SSLProtocol' => 'on',
            'CertId' => $cert_id,
        ];
        $client->request($param);
        $this->log('CDN域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_dcdn($cert_id, $cert_name, $config)
    {
        $domain = $config['domain'];
        if (empty($domain)) throw new Exception('DCDN绑定域名不能为空');
        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, 'dcdn.aliyuncs.com', '2018-01-15', $this->proxy);
        $param = [
            'Action' => 'SetDcdnDomainSSLCertificate',
            'DomainName' => $domain,
            'CertName' => $cert_name,
            'CertType' => 'cas',
            'SSLProtocol' => 'on',
            'CertId' => $cert_id,
        ];
        $client->request($param);
        $this->log('DCDN域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_esa($cas_id, $config)
    {
        $sitename = $config['esa_sitename'];
        if (empty($sitename)) throw new Exception('ESA站点名称不能为空');

        if ($config['region'] == 'cn-hangzhou') {
            $endpoint = 'esa.cn-hangzhou.aliyuncs.com';
        } else {
            $endpoint = 'esa.ap-southeast-1.aliyuncs.com';
        }

        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2024-09-10');
        $param = [
            'Action' => 'ListSites',
            'SiteName' => $sitename,
            'SiteSearchType' => 'exact',
        ];
        try {
            $data = $client->request($param, 'GET');
        } catch (Exception $e) {
            throw new Exception('查询ESA站点列表失败：' . $e->getMessage());
        }
        if ($data['TotalCount'] == 0) throw new Exception('ESA站点 ' . $sitename . ' 不存在');
        $this->log('成功查询到' . $data['TotalCount'] . '个ESA站点');
        $site_id = $data['Sites'][0]['SiteId'];

        $param = [
            'Action' => 'ListCertificates',
            'SiteId' => $site_id,
        ];
        try {
            $data = $client->request($param, 'GET');
        } catch (Exception $e) {
            throw new Exception('查询ESA站点' . $sitename . '证书列表失败：' . $e->getMessage());
        }
        $this->log('ESA站点 ' . $sitename . ' 查询到' . $data['TotalCount'] . '个SSL证书');

        $cert_id = null;
        $cert_name = null;
        $casid = null;
        foreach ($data['Result'] as $cert) {
            $domains = explode(',', $cert['SAN']);
            $flag = true;
            foreach ($domains as $domain) {
                if (!in_array($domain, $config['domainList'])) {
                    $flag = false;
                    break;
                }
            }
            if ($flag) {
                $cert_id = $cert['Id'];
                $cert_name = $cert['CommonName'];
                $casid = $cert['CasId'];
                break;
            }
        }

        $param = [
            'Action' => 'SetCertificate',
            'SiteId' => $site_id,
            'Type' => 'cas',
            'CasId' => $cas_id,
        ];
        if ($cert_id) {
            $param['Update'] = 'true';
            $param['Id'] = $cert_id;
            if ($casid == $cas_id) {
                $this->log('ESA站点 ' . $sitename . ' 证书已配置，无需重复操作');
                return;
            }
        }
        $client->request($param);
        if ($cert_id) {
            $this->log('ESA站点 ' . $sitename . ' 域名 ' . $cert_name . ' 更新证书成功！');
        } else {
            $this->log('ESA站点 ' . $sitename . ' 添加证书成功！');
        }
    }

    private function deploy_oss($cert_id, $config)
    {
        if (empty($config['domain'])) throw new Exception('OSS绑定域名不能为空');
        if (empty($config['oss_endpoint'])) throw new Exception('OSS Endpoint不能为空');
        if (empty($config['oss_bucket'])) throw new Exception('OSS Bucket不能为空');
        $client = new AliyunOSS($this->AccessKeyId, $this->AccessKeySecret, $config['oss_endpoint']);
        $client->addBucketCnameCert($config['oss_bucket'], $config['domain'], $cert_id . '-cn-hangzhou');
        $this->log('OSS域名 ' . $config['domain'] . ' 部署证书成功！');
    }

    private function deploy_waf($cert_id, $config)
    {
        $domain = $config['domain'];
        if (empty($domain)) throw new Exception('WAF绑定域名不能为空');

        $endpoint = 'wafopenapi.' . $config['region'] . '.aliyuncs.com';

        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2021-10-01', $this->proxy);

        $param = [
            'Action' => 'DescribeInstance',
            'RegionId' => $config['region'],
        ];
        try {
            $data = $client->request($param, 'GET');
        } catch (Exception $e) {
            throw new Exception('获取WAF实例详情失败：' . $e->getMessage());
        }
        if (empty($data['InstanceId'])) throw new Exception('当前账号未找到WAF实例');
        $instance_id = $data['InstanceId'];
        $this->log('获取WAF实例ID成功 InstanceId=' . $instance_id);

        $param = [
            'Action' => 'DescribeDomainDetail',
            'InstanceId' => $instance_id,
            'Domain' => $domain,
            'RegionId' => $config['region'],
        ];
        try {
            $data = $client->request($param, 'GET');
        } catch (Exception $e) {
            throw new Exception('查询CNAME接入详情失败：' . $e->getMessage());
        }

        if (isset($data['Listen']['CertId'])) {
            $old_cert_id = $data['Listen']['CertId'];
            if (strpos($old_cert_id, '-')) $old_cert_id = substr($old_cert_id, 0, strpos($old_cert_id, '-'));
            if (!empty($old_cert_id) && $old_cert_id == $cert_id) {
                $this->log('WAF域名 ' . $domain . ' 证书已配置，无需重复操作');
                return;
            }
        }

        $data['Listen']['CertId'] = $cert_id . '-cn-hangzhou';
        if (empty($data['Listen']['HttpsPorts'])) $data['Listen']['HttpsPorts'] = [443];
        $data['Redirect']['Backends'] = $data['Redirect']['AllBackends'];
        $param = [
            'Action' => 'ModifyDomain',
            'InstanceId' => $instance_id,
            'Domain' => $domain,
            'Listen' => json_encode($data['Listen']),
            'Redirect' => json_encode($data['Redirect']),
            'RegionId' => $config['region'],
        ];
        $data = $client->request($param);

        $this->log('WAF域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_waf2($cert_id, $config)
    {
        $domain = $config['domain'];
        if (empty($domain)) throw new Exception('WAF绑定域名不能为空');

        $endpoint = 'wafopenapi.' . $config['region'] . '.aliyuncs.com';

        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2019-09-10', $this->proxy);

        $param = [
            'Action' => 'DescribeInstanceInfo',
            'RegionId' => $config['region'],
        ];
        try {
            $data = $client->request($param, 'GET');
        } catch (Exception $e) {
            throw new Exception('获取WAF实例详情失败：' . $e->getMessage());
        }
        if (empty($data['InstanceInfo']['InstanceId'])) throw new Exception('当前账号未找到WAF实例');
        $instance_id = $data['InstanceInfo']['InstanceId'];
        $this->log('获取WAF实例ID成功 InstanceId=' . $instance_id);

        $param = [
            'Action' => 'CreateCertificateByCertificateId',
            'InstanceId' => $instance_id,
            'Domain' => $domain,
            'CertificateId' => $cert_id,
        ];
        $client->request($param);

        $this->log('WAF域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_api($fullchain, $privatekey, $config)
    {
        $domain = $config['domain'];
        $groupid = $config['api_groupid'];
        if (empty($groupid)) throw new Exception('API分组ID不能为空');
        if (empty($domain)) throw new Exception('API分组绑定域名不能为空');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $endpoint = 'apigateway.' . $config['regionid'] . '.aliyuncs.com';

        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2016-07-14', $this->proxy);

        $param = [
            'Action' => 'SetDomainCertificate',
            'GroupId' => $groupid,
            'DomainName' => $domain,
            'CertificateName' => $cert_name,
            'CertificateBody' => $fullchain,
            'CertificatePrivateKey' => $privatekey,
        ];
        $client->request($param);

        $this->log('API网关域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_ddoscoo($cert_id, $config)
    {
        $domain = $config['domain'];
        if (empty($domain)) throw new Exception('绑定域名不能为空');

        $endpoint = 'ddoscoo.' . $config['region'] . '.aliyuncs.com';

        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2020-01-01', $this->proxy);

        $param = [
            'Action' => 'AssociateWebCert',
            'Domain' => $domain,
            'CertId' => $cert_id,
        ];
        $client->request($param);

        $this->log('DDoS高防域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_live($cert_id, $cert_name, $config)
    {
        $domain = $config['domain'];
        if (empty($domain)) throw new Exception('视频直播绑定域名不能为空');
        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, 'live.aliyuncs.com', '2016-11-01', $this->proxy);
        $param = [
            'Action' => 'SetLiveDomainCertificate',
            'DomainName' => $domain,
            'CertName' => $cert_name,
            'CertType' => 'cas',
            'SSLProtocol' => 'on',
            'CertId' => $cert_id,
        ];
        $client->request($param);
        $this->log('设置视频直播域名 ' . $domain . ' 证书成功！');
    }

    private function deploy_vod($fullchain, $privatekey, $config)
    {
        $domain = $config['domain'];
        if (empty($domain)) throw new Exception('视频点播绑定域名不能为空');
        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, 'vod.cn-shanghai.aliyuncs.com', '2017-03-21', $this->proxy);
        $param = [
            'Action' => 'SetVodDomainCertificate',
            'DomainName' => $domain,
            'SSLProtocol' => 'on',
            'SSLPub' => $fullchain,
            'SSLPri' => $privatekey,
        ];
        $client->request($param);
        $this->log('视频点播域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_fc($fullchain, $privatekey, $config)
    {
        $domain = $config['domain'];
        $fc_cname = $config['fc_cname'];
        if (empty($domain)) throw new Exception('函数计算域名不能为空');
        if (empty($fc_cname)) throw new Exception('域名CNAME地址不能为空');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new AliyunNewClient($this->AccessKeyId, $this->AccessKeySecret, $fc_cname, '2023-03-30', $this->proxy);

        try {
            $data = $client->request('GET', 'GetCustomDomain', '/2023-03-30/custom-domains/' . $domain);
        } catch (Exception $e) {
            throw new Exception('获取绑定域名信息失败：' . $e->getMessage());
        }
        $this->log('获取函数计算绑定域名信息成功');

        if (isset($data['certConfig']['certificate']) && $data['certConfig']['certificate'] == $fullchain) {
            $this->log('函数计算域名 ' . $domain . ' 证书已配置，无需重复操作');
            return;
        }

        if ($data['protocol'] == 'HTTP') $data['protocol'] = 'HTTP,HTTPS';
        $data['certConfig']['certName'] = $cert_name;
        $data['certConfig']['certificate'] = $fullchain;
        $data['certConfig']['privateKey'] = $privatekey;

        $param = [
            'authConfig' => $data['authConfig'],
            'certConfig' => $data['certConfig'],
            'protocol' => $data['protocol'],
            'routeConfig' => $data['routeConfig'],
            'tlsConfig' => $data['tlsConfig'],
            'wafConfig' => $data['wafConfig'],
        ];
        $client->request('PUT', 'UpdateCustomDomain', '/2023-03-30/custom-domains/' . $domain, $param);

        $this->log('函数计算域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_fc2($fullchain, $privatekey, $config)
    {
        $domain = $config['domain'];
        $fc_cname = $config['fc_cname'];
        if (empty($domain)) throw new Exception('函数计算域名不能为空');
        if (empty($fc_cname)) throw new Exception('域名CNAME地址不能为空');

        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');
        $cert_name = str_replace('*.', '', $certInfo['subject']['CN']) . '-' . $certInfo['validFrom_time_t'];

        $client = new AliyunNewClient($this->AccessKeyId, $this->AccessKeySecret, $fc_cname, '2021-04-06', $this->proxy);

        try {
            $data = $client->request('GET', 'GetCustomDomain', '/2021-04-06/custom-domains/' . $domain);
        } catch (Exception $e) {
            throw new Exception('获取绑定域名信息失败：' . $e->getMessage());
        }
        $this->log('获取函数计算绑定域名信息成功');

        if (isset($data['certConfig']['certificate']) && $data['certConfig']['certificate'] == $fullchain) {
            $this->log('函数计算域名 ' . $domain . ' 证书已配置，无需重复操作');
            return;
        }

        if ($data['protocol'] == 'HTTP') $data['protocol'] = 'HTTP,HTTPS';
        $data['certConfig']['certName'] = $cert_name;
        $data['certConfig']['certificate'] = $fullchain;
        $data['certConfig']['privateKey'] = $privatekey;

        $param = [
            'protocol' => $data['protocol'],
            'routeConfig' => $data['routeConfig'],
            'certConfig' => $data['certConfig'],
            'tlsConfig' => $data['tlsConfig'],
            'wafConfig' => $data['wafConfig'],
        ];
        $client->request('PUT', 'UpdateCustomDomain', '/2021-04-06/custom-domains/' . $domain, $param);

        $this->log('函数计算域名 ' . $domain . ' 部署证书成功！');
    }

    private function deploy_clb($cert_id, $cert_name, $config)
    {
        if (empty($config['clb_id'])) throw new Exception('负载均衡实例ID不能为空');
        if (empty($config['clb_port'])) throw new Exception('HTTPS监听端口不能为空');

        $endpoint = 'slb.' . $config['regionid'] . '.aliyuncs.com';
        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2014-05-15', $this->proxy);

        $param = [
            'Action' => 'DescribeServerCertificates',
            'RegionId' => $config['regionid'],
        ];
        try {
            $data = $client->request($param);
        } catch (Exception $e) {
            throw new Exception('获取服务器证书列表失败：' . $e->getMessage());
        }

        $ServerCertificateId = null;
        foreach ($data['ServerCertificates']['ServerCertificate'] as $cert) {
            if ($cert['IsAliCloudCertificate'] == 1 && $cert['AliCloudCertificateId'] == $cert_id) {
                $ServerCertificateId = $cert['ServerCertificateId'];
                break;
            }
        }
        if (!$ServerCertificateId) {
            $param = [
                'Action' => 'UploadServerCertificate',
                'RegionId' => $config['regionid'],
                'AliCloudCertificateId' => $cert_id,
                'AliCloudCertificateName' => $cert_name,
                'AliCloudCertificateRegionId' => 'cn-hangzhou',
            ];
            try {
                $data = $client->request($param);
            } catch (Exception $e) {
                throw new Exception('服务器证书添加失败：' . $e->getMessage());
            }
            $ServerCertificateId = $data['ServerCertificateId'];
            $this->log('服务器证书添加成功 ServerCertificateId=' . $ServerCertificateId);
        } else {
            $this->log('找到已添加的服务器证书 ServerCertificateId=' . $ServerCertificateId);
        }

        $param = [
            'Action' => 'DescribeLoadBalancerHTTPSListenerAttribute',
            'RegionId' => $config['regionid'],
            'LoadBalancerId' => $config['clb_id'],
            'ListenerPort' => $config['clb_port'],
        ];
        try {
            $data = $client->request($param);
        } catch (Exception $e) {
            throw new Exception('HTTPS监听配置查询失败：' . $e->getMessage());
        }

        if ($data['ServerCertificateId'] == $ServerCertificateId) {
            $this->log('负载均衡HTTPS监听已配置该证书，无需重复操作');
            return;
        }

        $param = [
            'Action' => 'SetLoadBalancerHTTPSListenerAttribute',
            'RegionId' => $config['regionid'],
            'LoadBalancerId' => $config['clb_id'],
            'ListenerPort' => $config['clb_port'],
        ];
        $keys = ['Bandwidth', 'XForwardedFor', 'Scheduler', 'StickySession', 'StickySessionType', 'CookieTimeout', 'Cookie', 'HealthCheck', 'HealthCheckMethod', 'HealthCheckDomain', 'HealthCheckURI', 'HealthyThreshold', 'UnhealthyThreshold', 'HealthCheckTimeout', 'HealthCheckInterval', 'HealthCheckConnectPort', 'HealthCheckHttpCode', 'ServerCertificateId', 'CACertificateId', 'VServerGroup', 'VServerGroupId', 'XForwardedFor_SLBIP', 'XForwardedFor_SLBID', 'XForwardedFor_proto', 'Gzip', 'AclId', 'AclType', 'AclStatus', 'IdleTimeout', 'RequestTimeout', 'EnableHttp2', 'TLSCipherPolicy', 'Description', 'XForwardedFor_SLBPORT', 'XForwardedFor_ClientSrcPort'];
        foreach ($keys as $key) {
            if (isset($data[$key])) $param[$key] = $data[$key];
        }
        $param['ServerCertificateId'] = $ServerCertificateId;
        $client->request($param);
        $this->log('负载均衡HTTPS监听证书配置成功！');
    }

    private function deploy_alb($cert_id, $config)
    {
        if (empty($config['alb_listener_id'])) throw new Exception('负载均衡监听ID不能为空');

        $endpoint = 'alb.' . $config['regionid'] . '.aliyuncs.com';
        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2020-06-16', $this->proxy);

        $param = [
            'Action' => 'ListListenerCertificates',
            'MaxResults' => 100,
            'ListenerId' => $config['alb_listener_id'],
            'CertificateType' => 'Server',
        ];
        try {
            $data = $client->request($param);
        } catch (Exception $e) {
            throw new Exception('获取监听证书列表失败：' . $e->getMessage());
        }
        foreach ($data['Certificates'] as $cert) {
            if (strpos($cert['CertificateId'], '-')) $cert['CertificateId'] = substr($cert['CertificateId'], 0, strpos($cert['CertificateId'], '-'));
            if ($cert['CertificateId'] == $cert_id) {
                $this->log('负载均衡监听证书已添加，无需重复操作');
                return;
            }
        }

        $param = [
            'Action' => 'AssociateAdditionalCertificatesWithListener',
            'ListenerId' => $config['alb_listener_id'],
            'Certificates.1.CertificateId' => $cert_id . '-cn-hangzhou',
        ];
        $client->request($param);
        $this->log('应用型负载均衡监听证书添加成功！');
    }

    private function deploy_nlb($cert_id, $config)
    {
        if (empty($config['nlb_listener_id'])) throw new Exception('负载均衡监听ID不能为空');

        $endpoint = 'nlb.' . $config['regionid'] . '.aliyuncs.com';
        $client = new AliyunClient($this->AccessKeyId, $this->AccessKeySecret, $endpoint, '2022-04-30', $this->proxy);

        $param = [
            'Action' => 'ListListenerCertificates',
            'MaxResults' => 50,
            'ListenerId' => $config['nlb_listener_id'],
            'CertificateType' => 'Server',
        ];
        try {
            $data = $client->request($param);
        } catch (Exception $e) {
            throw new Exception('获取监听证书列表失败：' . $e->getMessage());
        }
        foreach ($data['Certificates'] as $cert) {
            if (strpos($cert['CertificateId'], '-')) $cert['CertificateId'] = substr($cert['CertificateId'], 0, strpos($cert['CertificateId'], '-'));
            if ($cert['CertificateId'] == $cert_id) {
                $this->log('负载均衡监听证书已添加，无需重复操作');
                return;
            }
        }

        $param = [
            'Action' => 'AssociateAdditionalCertificatesWithListener',
            'ListenerId' => $config['nlb_listener_id'],
            'AdditionalCertificateIds.1' => $cert_id . '-cn-hangzhou',
        ];
        $client->request($param);
        $this->log('网络型负载均衡监听证书添加成功！');
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
