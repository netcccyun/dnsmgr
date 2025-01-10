<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class safeline implements DeployInterface
{
    private $logger;
    private $url;
    private $token;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->token = $config['token'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->token)) throw new Exception('请填写控制台地址和API Token');
        $this->request('/api/open/system');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domains = $config['domainList'];
        if (empty($domains)) throw new Exception('没有设置要部署的域名');

        try {
            $data = $this->request('/api/open/cert');
            $this->log('获取证书列表成功(total=' . $data['total'] . ')');
        } catch (Exception $e) {
            throw new Exception('获取证书列表失败：' . $e->getMessage());
        }

        $success = 0;
        $errmsg = null;
        foreach ($data['nodes'] as $row) {
            if (empty($row['domains'])) continue;
            $flag = false;
            foreach ($row['domains'] as $domain) {
                if (in_array($domain, $domains)) {
                    $flag = true;
                    break;
                }
            }
            if ($flag) {
                $params = [
                    'id' => $row['id'],
                    'manual' => [
                        'crt' => $fullchain,
                        'key' => $privatekey,
                    ],
                    'type' => 2,
                ];
                try {
                    $this->request('/api/open/cert', $params);
                    $this->log("证书ID:{$row['id']}更新成功！");
                    $success++;
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                    $this->log("证书ID:{$row['id']}更新失败：" . $errmsg);
                }
            }
        }
        if ($success == 0) {
            $params = [
                'manual' => [
                    'crt' => $fullchain,
                    'key' => $privatekey,
                ],
                'type' => 2,
            ];
            $this->request('/api/open/cert', $params);
            $this->log("证书上传成功！");
        }
    }

    private function request($path, $params = null)
    {
        $url = $this->url . $path;
        $headers = ['X-SLCE-API-TOKEN: ' . $this->token];
        $body = null;
        if ($params) {
            $heders[] = 'Content-Type: application/json';
            $body = json_encode($params);
        }
        $response = curl_client($url, $body, null, null, $headers, $this->proxy);
        $result = json_decode($response['body'], true);
        if ($response['code'] == 200 && $result) {
            return isset($result['data']) ? $result['data'] : null;
        } else {
            throw new Exception(!empty($result['msg']) ? $result['msg'] : '请求失败(httpCode=' . $response['code'] . ')');
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
