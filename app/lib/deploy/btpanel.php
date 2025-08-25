<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use app\lib\CertHelper;
use Exception;

class btpanel implements DeployInterface
{
    private $logger;
    private $url;
    private $key;
    private $version;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->key = $config['key'];
        $this->version = isset($config['version']) ? intval($config['version']) : 0;
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->key)) throw new Exception('请填写面板地址和接口密钥');

        if ($this->version == 1) {
            $path = '/config/get_config';
            $response = $this->request($path, []);
            $result = json_decode($response, true);
            if (isset($result['panel']['status']) && $result['panel']['status']) {
                return true;
            } else {
                throw new Exception(isset($result['msg']) ? $result['msg'] : '面板地址无法连接');
            }
        } else {
            $path = '/config?action=get_config';
            $response = $this->request($path, []);
            $result = json_decode($response, true);
            if (isset($result['status']) && ($result['status'] == 1 || isset($result['sites_path']))) {
                return true;
            } else {
                throw new Exception(isset($result['msg']) ? $result['msg'] : '面板地址无法连接');
            }
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if ($config['type'] == '1') {
            $this->deployPanel($fullchain, $privatekey);
            $this->log("面板证书部署成功");
            return;
        }

        $isIIS = $config['type'] == '0' && $this->version == 1 && isset($config['is_iis']) && $config['is_iis'] == '1';
        if ($isIIS) {
            $response = $this->request('/panel/get_config', []);
            $result = json_decode($response, true);
            if (isset($result['paths']['soft'])) {
                if ($result['config']['webserver'] != 'iis') {
                    throw new Exception('当前安装的Web服务器不是IIS');
                }
                $panel_path = $result['paths']['soft'];
            } else {
                throw new Exception(isset($result['msg']) ? $result['msg'] : '面板地址无法连接');
            }

            $pfx_dir = $panel_path . '/temp/ssl/' . getMillisecond();
            $pfx_path = $pfx_dir . '/cert.pfx';
            $pfx_password = '123456';
            $pfx = CertHelper::getPfx($fullchain, $privatekey, $pfx_password);
            $data = [
                ['name' => 'path', 'contents' => $pfx_dir],
                ['name' => 'filename', 'contents' => 'cert.pfx'],
                ['name' => 'size', 'contents' => strlen($pfx)],
                ['name' => 'start', 'contents' => '0'],
                ['name' => 'blob', 'filename' => 'cert.pfx', 'contents' => $pfx],
                ['name' => 'force', 'contents' => 'true'],
            ];
            $response = $this->request('/files/upload', $data, true);
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status']) {
            } else {
                throw new Exception(isset($result['msg']) ? $result['msg'] : '面板地址无法连接');
            }
        }

        $sites = explode("\n", $config['sites']);
        $success = 0;
        $errmsg = null;
        foreach ($sites as $site) {
            $siteName = trim($site);
            if (empty($siteName)) continue;
            if ($config['type'] == '3') {
                try {
                    $this->deployDocker($siteName, $fullchain, $privatekey);
                    $this->log("Docker域名 {$siteName} 证书部署成功");
                    $success++;
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                    $this->log("Docker域名 {$siteName} 证书部署失败：" . $errmsg);
                }
            } elseif ($config['type'] == '2') {
                try {
                    $this->deployMailSys($siteName, $fullchain, $privatekey);
                    $this->log("邮局域名 {$siteName} 证书部署成功");
                    $success++;
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                    $this->log("邮局域名 {$siteName} 证书部署失败：" . $errmsg);
                }
            } elseif ($isIIS) {
                try {
                    $this->deployIISSite($siteName, $pfx_path, $pfx_password);
                    $this->log("域名 {$siteName} 证书部署成功");
                    $success++;
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                    $this->log("域名 {$siteName} 证书部署失败：" . $errmsg);
                }
            } else {
                try {
                    $this->deploySite($siteName, $fullchain, $privatekey);
                    $this->log("网站 {$siteName} 证书部署成功");
                    $success++;
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                    $this->log("网站 {$siteName} 证书部署失败：" . $errmsg);
                }
            }
        }
        if ($success == 0) {
            throw new Exception($errmsg ? $errmsg : '要部署的网站不存在');
        }
    }

    private function deployPanel($fullchain, $privatekey)
    {
        if ($this->version == 1) {
            $path = '/config/set_panel_ssl';
            $data = [
                'ssl_key' => $privatekey,
                'ssl_pem' => $fullchain,
            ];
            $response = $this->request($path, $data);
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status']) {
                return true;
            } elseif (isset($result['msg'])) {
                throw new Exception($result['msg']);
            } else {
                throw new Exception($response ? $response : '返回数据解析失败');
            }
        } else {
            $path = '/config?action=SavePanelSSL';
            $data = [
                'privateKey' => $privatekey,
                'certPem' => $fullchain,
            ];
            $response = $this->request($path, $data);
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status']) {
                return true;
            } elseif (isset($result['msg'])) {
                throw new Exception($result['msg']);
            } else {
                throw new Exception($response ? $response : '返回数据解析失败');
            }
        }
    }

    private function deploySite($siteName, $fullchain, $privatekey)
    {
        if ($this->version == 1) {
            $path = '/datalist/get_data_list';
            $data = [
                'table' => 'sites',
                'search_type' => 'PHP',
                'search' => $siteName,
                'p' => 1,
                'limit' => 10,
                'type' => -1,
            ];
            $response = $this->request($path, $data);
            $result = json_decode($response, true);
            if (isset($result['data'])) {
                if (empty($result['data'])) throw new Exception("网站 {$siteName} 不存在");
                $siteId = null;
                foreach ($result['data'] as $item) {
                    if ($item['name'] == $siteName) {
                        $siteId = $item['id'];
                        break;
                    }
                }
                if (is_null($siteId)) throw new Exception("网站 {$siteName} 不存在");
                $path = '/site/set_site_ssl';
                $data = [
                    'siteid' => $siteId,
                    'status' => 'true',
                    'sslType' => '',
                    'cert' => $fullchain,
                    'key' => $privatekey,
                ];
                $response = $this->request($path, $data);
                $result = json_decode($response, true);
                if (isset($result['status']) && $result['status']) {
                    return true;
                } elseif (isset($result['msg'])) {
                    throw new Exception($result['msg']);
                } else {
                    throw new Exception($response ? $response : '返回数据解析失败');
                }
                return true;
            } elseif (isset($result['msg'])) {
                throw new Exception($result['msg']);
            } else {
                throw new Exception($response ? $response : '返回数据解析失败');
            }
        } else {
            $path = '/site?action=SetSSL';
            $data = [
                'type' => '0',
                'siteName' => $siteName,
                'key' => $privatekey,
                'csr' => $fullchain,
            ];
            $response = $this->request($path, $data);
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status']) {
                return true;
            } elseif (isset($result['msg'])) {
                throw new Exception($result['msg']);
            } else {
                throw new Exception($response ? $response : '返回数据解析失败');
            }
        }
    }

    private function deployIISSite($domain, $pfx_path, $password = '123456')
    {
        $path = '/site/set_site_domain_ssl';
        $data = [
            'domain' => $domain,
            'path' => $pfx_path,
            'password' => $password,
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status']) {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
    }

    private function deployMailSys($domain, $fullchain, $privatekey)
    {
        $path = '/plugin?action=a&name=mail_sys&s=set_mail_certificate_multiple';
        $data = [
            'domain' => $domain,
            'key' => $privatekey,
            'csr' => $fullchain,
            'act' => 'add',
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status']) {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
        }
    }

    private function deployDocker($domain, $fullchain, $privatekey)
    {
        $path = '/mod/docker/com/set_ssl';
        $data = [
            'site_name' => $domain,
            'key' => $privatekey,
            'csr' => $fullchain,
        ];
        $response = $this->request($path, $data);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status']) {
            return true;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception($response ? $response : '返回数据解析失败');
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

    private function request($path, $params, $file = false)
    {
        $url = $this->url . $path;

        $now_time = time();
        $headers = [];
        if ($file) {
            $post_data = [
                ['name' => 'request_token', 'contents' => md5($now_time . md5($this->key))],
                ['name' => 'request_time', 'contents' => $now_time],
            ];
            $post_data = array_merge($post_data, $params);
            $headers['Content-Type'] = 'multipart/form-data';
        } else {
            $post_data = [
                'request_token' => md5($now_time . md5($this->key)),
                'request_time' => $now_time
            ];
            $post_data = array_merge($post_data, $params);
        }
        $response = http_request($url, $post_data, null, null, $headers, $this->proxy);
        return $response['body'];
    }
}
