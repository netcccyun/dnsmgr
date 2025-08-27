<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class unicloud implements DeployInterface
{
    private $logger;
    private $username;
    private $password;
    private $deviceId;
    private $proxy;
    private $token;

    public function __construct($config)
    {
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->proxy = $config['proxy'] == 1;
        $this->deviceId = getMillisecond() . random(7, 1);
    }

    public function check()
    {
        if (empty($this->username) || empty($this->password)) throw new Exception('账号或密码不能为空');
        $this->login();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        if (empty($config['domains'])) throw new Exception('绑定的域名不能为空');
        $this->getToken();

        $url = 'https://unicloud-api.dcloud.net.cn/unicloud/api/host/create-domain-with-cert';
        foreach (explode(',', $config['domains']) as $domain) {
            if (empty($domain)) continue;
            $params = [
                'appid' => '',
                'provider' => $config['provider'],
                'spaceId' => $config['spaceId'],
                'domain' => $domain,
                'cert' => rawurlencode($fullchain),
                'key' => rawurlencode($privatekey),
            ];
            $post = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers = [
                'Token' => $this->token,
            ];
            $response = http_request($url, $post, null, null, $headers, $this->proxy);
            $result = json_decode($response['body'], true);
            if (isset($result['ret']) && $result['ret'] == 0) {
                $this->log('域名:' . $domain . ' 证书更新成功！');
            } elseif(isset($result['desc'])) {
                throw new Exception('域名:' . $domain . ' 证书更新失败:' . $result['desc']);
            } else {
                throw new Exception('域名:' . $domain . ' 证书更新失败:' . $response['body']);
            }
        }
    }

    private function login()
    {
        $url = 'https://account.dcloud.net.cn/client';
        $clientInfo = $this->getClientInfo('__UNI__unicloud_console', '账号中心');
        $bizParams = [
            'functionTarget' => 'uni-id-co',
            'functionArgs' => [
                'method' => 'login',
                'params' => [[
                    'password' => $this->password,
                    'captcha' => '',
                    'resetAppId' => '__UNI__unicloud_console',
                    'resetUniPlatform' => 'web',
                    'isReturnToken' => false,
                    'email' => $this->username,
                ]],
                'clientInfo' => $clientInfo,
            ],
        ];
        $params = [
            'method' => 'serverless.function.runtime.invoke',
            'params' => json_encode($bizParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'spaceId' => 'uni-id-server',
            'timestamp' => getMillisecond(),
        ];
        $sign = $this->sign($params, 'ba461799-fde8-429f-8cc4-4b6d306e2339');
        $post = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Origin' => 'https://account.dcloud.net.cn',
            'Referer' => 'https://account.dcloud.net.cn/',
            'X-Client-Info' => json_encode($clientInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'X-Serverless-Sign' => $sign,
        ];
        $response = http_request($url, $post, null, null, $headers, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['success']) && $result['success'] == true) {
            if (isset($result['data']['errCode']) && $result['data']['errCode'] == 0) {
                return $result['data']['newToken']['token'];
            } else {
                throw new Exception('登录失败:' . $result['data']['errMsg']);
            }
        } else {
            throw new Exception('登录失败:' . $response['body']);
        }
    }

    private function getToken()
    {
        $uniIdToken = $this->login();
        $url = 'https://unicloud.dcloud.net.cn/client';
        $clientInfo = $this->getClientInfo('__UNI__unicloud_console', 'uniCloud控制台');
        $bizParams = [
            'functionTarget' => 'uni-cloud-kernel',
            'functionArgs' => [
                'action' => 'user/getUserToken',
                'data' => [
                    'isLogin' => true
                ],
                'clientInfo' => $clientInfo,
                'uniIdToken' => $uniIdToken,
            ],
        ];
        $params = [
            'method' => 'serverless.function.runtime.invoke',
            'params' => json_encode($bizParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'spaceId' => 'dc-6nfabcn6ada8d3dd',
            'timestamp' => getMillisecond(),
        ];
        $sign = $this->sign($params, '4c1f7fbf-c732-42b0-ab10-4634a8bbe834');
        $post = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Origin' => 'https://account.dcloud.net.cn',
            'Referer' => 'https://account.dcloud.net.cn/',
            'X-Client-Info' => json_encode($clientInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'X-Client-Token' => $uniIdToken,
            'X-Serverless-Sign' => $sign,
        ];
        $response = http_request($url, $post, null, null, $headers, $this->proxy);
        $result = json_decode($response['body'], true);
        if (isset($result['success']) && $result['success'] == true) {
            if (isset($result['data']['code']) && $result['data']['code'] == 0) {
                if (isset($result['data']['data']['ret']) && $result['data']['data']['ret'] == 0) {
                    $this->token = $result['data']['data']['data']['token'];
                    return $result['data']['data']['data']['token'];
                } else {
                    throw new Exception('获取token失败:' . $result['data']['data']['desc']);
                }
            } else {
                throw new Exception('获取token失败:' . $response['body']);
            }
        } else {
            throw new Exception('获取token失败:' . $response['body']);
        }
    }

    private function getClientInfo($appId, $appName, $appVersion = '1.0.0', $appVersionCode = '100')
    {
        $clientInfo = [
            'PLATFORM' => 'web',
            'OS' => 'windows',
            'APPID' => $appId,
            'DEVICEID' => $this->deviceId,
            'scene' => 1001,
            'appId' => $appId,
            'appLanguage' => 'zh-Hans',
            'appName' => $appName,
            'appVersion' => $appVersion,
            'appVersionCode' => $appVersionCode,
            'browserName' => 'chrome',
            'browserVersion' => '122.0.6261.95',
            'deviceId' => $this->deviceId,
            'deviceModel' => 'PC',
            'deviceType' => 'pc',
            'hostName' => 'chrome',
            'hostVersion' => '122.0.6261.95',
            'osName' => 'windows',
            'osVersion' => '10 x64',
            'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.6261.95 Safari/537.36',
            'uniCompilerVersion' => '4.45',
            'uniPlatform' => 'web',
            'uniRuntimeVersion' => '4.45',
            'locale' => 'zh-Hans',
            'LOCALE' => 'zh-Hans',
        ];
        return $clientInfo;
    }

    private function sign($data, $key)
    {
        ksort($data);
        $signstr = '';
        foreach ($data as $k => $v) {
            $signstr .= $k . '=' . $v . '&';
        }
        $signstr = rtrim($signstr, '&');
        return hash_hmac('md5', $signstr, $key);
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
