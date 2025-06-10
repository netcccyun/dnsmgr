<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class mwpanel implements DeployInterface
{
    private $logger;
    private $url;
    private $appid;
    private $appsecret;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->appid = $config['appid'];
        $this->appsecret = $config['appsecret'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->appid) || empty($this->appsecret)) throw new Exception('请填写面板地址和接口密钥');

        $path = '/task/count';
        $response = $this->request($path);
        $result = json_decode($response, true);
        if (isset($result['status']) && $result['status'] == true) {
            return true;
        } else {
            throw new Exception(isset($result['msg']) ? $result['msg'] : '面板地址无法连接');
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if ($config['type'] == '1') {
            $this->deployPanel($fullchain, $privatekey);
            $this->log("面板证书部署成功");
            return;
        }
        $sites = explode("\n", $config['sites']);
        $success = 0;
        $errmsg = null;
        foreach ($sites as $site) {
            $siteName = trim($site);
            if (empty($siteName)) continue;
            try {
                $this->deploySite($siteName, $fullchain, $privatekey);
                $this->log("网站 {$siteName} 证书部署成功");
                $success++;
            } catch (Exception $e) {
                $errmsg = $e->getMessage();
                $this->log("网站 {$siteName} 证书部署失败：" . $errmsg);
            }
        }
        if ($success == 0) {
            throw new Exception($errmsg ? $errmsg : '要部署的网站不存在');
        }
    }

    private function deployPanel($fullchain, $privatekey)
    {
        $path = '/setting/save_panel_ssl';
        $data = [
            'privateKey' => $privatekey,
            'certPem' => $fullchain,
            'choose' => 'local',
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

    private function deploySite($siteName, $fullchain, $privatekey)
    {
        $path = '/site/set_ssl';
        $data = [
            'type' => '1',
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

    private function request($path, $params = null)
    {
        $url = $this->url . $path;

        $headers = [
            'app-id' => $this->appid,
            'app-secret' => $this->appsecret,
        ];
        $response = http_request($url, $params ? http_build_query($params) : null, null, null, $headers, $this->proxy);
        return $response['body'];
    }
}
