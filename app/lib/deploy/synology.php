<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class synology implements DeployInterface
{
    private $logger;
    private $url;
    private $username;
    private $password;
    private $version;
    private $token;
    private $proxy;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->version = $config['version'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->username) || empty($this->password)) throw new Exception('必填内容不能为空');
        $this->login();
    }

    private function login()
    {
        $url = $this->url . '/webapi/' . ($this->version == '1' ? 'auth.cgi' : 'entry.cgi');
        $params = [
            'api' => 'SYNO.API.Auth',
            'version' => 6,
            'method' => 'login',
            'account' => $this->username,
            'passwd' => $this->password,
            'format' => 'sid',
            'enable_syno_token' => 'yes',
        ];
        $response = curl_client($url, http_build_query($params), null, null, null, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['success']) && $result['success']) {
            $this->token = $result['data'];
        } elseif(isset($result['error'])) {
            throw new Exception('登录失败：' . $result['error']);
        } else {
            throw new Exception('请求失败(httpCode=' . $response['code'] . ')');
        }
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo) throw new Exception('证书解析失败');

        $url = $this->url . '/webapi/entry.cgi';
        $params = [
            'api' => 'SYNO.Core.Certificate.CRT',
            'version' => 1,
            'method' => 'list',
            '_sid' => $this->token['sid'],
            'SynoToken' => $this->token['synotoken'],
        ];
        $response = curl_client($url, http_build_query($params), null, null, null, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['success']) && $result['success']) {
            $this->log('获取证书列表成功');
        } elseif(isset($result['error'])) {
            throw new Exception('获取证书列表失败：' . $result['error']);
        } else {
            throw new Exception('获取证书列表失败(httpCode=' . $response['code'] . ')');
        }

        $id = null;
        foreach ($result['data']['certificates'] as $certificate) {
            if ($certificate['subject']['common_name'] == $certInfo['subject']['CN'] || $certificate['desc'] == $config['desc']) {
                $id = $certificate['id'];
                break;
            }
        }
        if ($id) {
            $this->import($fullchain, $privatekey, $config, $id);
        } else {
            $this->import($fullchain, $privatekey, $config);
        }
    }

    private function import($fullchain, $privatekey, $config, $id = null)
    {
        $url = $this->url . '/webapi/entry.cgi';
        $params = [
            'api' => 'SYNO.Core.Certificate',
            'version' => 1,
            'method' => 'import',
            '_sid' => $this->token['sid'],
            'SynoToken' => $this->token['synotoken'],
        ];
        $privatekey_file = tempnam(sys_get_temp_dir(), 'privatekey');
        file_put_contents($privatekey_file, $privatekey);
        $fullchain_file = tempnam(sys_get_temp_dir(), 'fullchain');
        file_put_contents($fullchain_file, $fullchain);
        $post = [
            'key' => new \CURLFile($privatekey_file),
            'cert' => new \CURLFile($fullchain_file),
            'id' => $id,
            'desc' => $config['desc'],
        ];
        $response = curl_client($url . '?' . http_build_query($params), $post, null, null, null, $this->proxy);
        unlink($privatekey_file);
        unlink($fullchain_file);
        $result = json_decode($response['body'], true);
        if ($id) {
            if (isset($result['success']) && $result['success']) {
                $this->log('证书ID:'.$id.'更新成功！');
            } elseif(isset($result['error'])) {
                throw new Exception('证书ID:'.$id.'更新失败：' . $result['error']);
            } else {
                throw new Exception('证书ID:'.$id.'更新失败(httpCode=' . $response['code'] . ')');
            }
        } else {
            if (isset($result['success']) && $result['success']) {
                $this->log('证书上传成功！');
            } elseif(isset($result['error'])) {
                throw new Exception('证书上传失败：' . $result['error']);
            } else {
                throw new Exception('证书上传失败(httpCode=' . $response['code'] . ')');
            }
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
