<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class lucky implements DeployInterface
{
    private $logger;
    private $url;
    private $opentoken;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/') . (!empty($config['path']) ? $config['path'] : '');
        $this->opentoken = $config['opentoken'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->opentoken)) throw new Exception('请填写面板地址和OpenToken');
        $this->request("/api/modules/list");
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domains = $config['domainList'];
        if (empty($domains)) throw new Exception('没有设置要部署的域名');

        try {
            $data = $this->request("/api/ssl");
            $this->log('获取证书列表成功');
        } catch (Exception $e) {
            throw new Exception('获取证书列表失败：' . $e->getMessage());
        }

        $success = 0;
        $errmsg = null;
        if (!empty($data['list'])) {
            foreach ($data['list'] as $row) {
                if (empty($row['CertsInfo']['Domains'])) continue;
                $cert_domains = $row['CertsInfo']['Domains'];
                $flag = false;
                foreach ($cert_domains as $domain) {
                    if (in_array($domain, $domains)) {
                        $flag = true;
                        break;
                    }
                }
                if ($flag) {
                    $params = [
                        'Key' => $row['Key'],
                        'CertBase64' => base64_encode($fullchain),
                        'KeyBase64' => base64_encode($privatekey),
                        'AddFrom' => 'file',
                        'Enable' => true,
                        'MappingToPath' => false,
                        'Remark' => $row['Remark'] ?: '',
                        'AllSyncClient' => false,
                    ];
                    try {
                        $this->request('/api/ssl', $params, 'PUT');
                        $this->log("证书ID:{$row['Key']}更新成功！");
                        $success++;
                    } catch (Exception $e) {
                        $errmsg = $e->getMessage();
                        $this->log("证书ID:{$row['Key']}更新失败：" . $errmsg);
                    }
                }
            }
        }
        if ($success == 0) {
            throw new Exception($errmsg ? $errmsg : '没有要更新的证书');
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

    private function request($path, $params = null, $method = null)
    {
        $url = $this->url . $path;

        $headers = [
            'openToken' => $this->opentoken,
        ];
        $body = null;
        if ($params) {
            $body = json_encode($params);
            $headers['Content-Type'] = 'application/json';
        }
        $response = http_request($url, $body, null, null, $headers, $this->proxy, $method);
        $result = json_decode($response['body'], true);
        if (isset($result['ret']) && $result['ret'] == 0) {
            return $result;
        } elseif (isset($result['msg'])) {
            throw new Exception($result['msg']);
        } else {
            throw new Exception('请求失败(httpCode=' . $response['code'] . ')');
        }
    }
}
