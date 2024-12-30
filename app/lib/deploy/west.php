<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class west implements DeployInterface
{
    private $logger;
    private $username;
    private $api_password;
    private $baseUrl = 'https://api.west.cn/api/v2';
    private $proxy;

    public function __construct($config)
    {
        $this->username = $config['username'];
        $this->api_password = $config['api_password'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->username) || empty($this->api_password)) throw new Exception('用户名或API密码不能为空');
        $this->execute('/vhost/', ['act' => 'products']);
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['sitename'])) throw new Exception('FTP账号不能为空');
        $params = [
            'act' => 'vhostssl',
            'sitename' => $config['sitename'],
            'cmd' => 'info'
        ];
        try {
            $data = $this->execute('/vhost/', $params);
        } catch (Exception $e) {
            throw new Exception('获取虚拟主机SSL配置失败:' . $e->getMessage());
        }

        $params = [
            'act' => 'vhostssl',
            'sitename' => $config['sitename'],
            'cmd' => 'import',
            'keycontent' => $privatekey,
            'certcontent' => $fullchain,
        ];
        try {
            $this->execute('/vhost/', $params);
        } catch (Exception $e) {
            throw new Exception('上传SSL证书失败:' . $e->getMessage());
        }
        $this->log('SSL证书上传成功');

        if (!isset($data['SSLEnabled']) || $data['SSLEnabled'] == 0) {
            $params = [
                'act' => 'vhostssl',
                'sitename' => $config['sitename'],
                'cmd' => 'openssl',
            ];
            try {
                $this->execute('/vhost/', $params);
            } catch (Exception $e) {
                throw new Exception('虚拟主机部署SSL失败:' . $e->getMessage());
            }
        } else {
            $params = [
                'act' => 'vhostssl',
                'sitename' => $config['sitename'],
                'cmd' => 'info'
            ];
            try {
                $data = $this->execute('/vhost/', $params);
            } catch (Exception $e) {
                throw new Exception('获取虚拟主机SSL配置失败:' . $e->getMessage());
            }
            if (!empty($data['sslcert']['ssl'])) {
                foreach ($data['sslcert']['ssl'] as $domain => $row) {
                    if (!in_array($domain, $config['domainList'])) continue;
                    $params = [
                        'act' => 'vhostssl',
                        'sitename' => $config['sitename'],
                        'cmd' => 'clearsslcache',
                        'sslid' => $row['sysid'],
                        'dm' => $domain,
                    ];
                    try {
                        $this->execute('/vhost/', $params);
                        $this->log('更新' . $domain . '证书缓存成功');
                    } catch (Exception $e) {
                        $this->log('更新' . $domain . '证书缓存失败:' . $e->getMessage());
                    }
                }
            }
        }
        $this->log('虚拟主机' . $config['sitename'] . '部署SSL成功');
    }

    private function execute($path, $params)
    {
        $params['username'] = $this->username;
        $params['time'] = getMillisecond();
        $params['token'] = md5($this->username . $this->api_password . $params['time']);
        $response = curl_client($this->baseUrl . $path, str_replace('+', '%20', http_build_query($params)), null, null, null, $this->proxy);
        $response = mb_convert_encoding($response['body'], 'UTF-8', 'GBK');
        $arr = json_decode($response, true);
        if ($arr) {
            if ($arr['result'] == 200) {
                return isset($arr['data']) ? $arr['data'] : [];
            } else {
                throw new Exception($arr['msg']);
            }
        } else {
            throw new Exception('请求失败(httpCode=' . $response['code'] . ')');
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
}
