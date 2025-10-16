<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class kangle implements DeployInterface
{
    private $logger;
    private $url;
    private $auth;
    private $username;
    private $password;
    private $skey;
    private $proxy;
    private $cookie;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->auth = $config['auth'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->skey = $config['skey'];
        $this->proxy = $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->username)) throw new Exception('必填参数不能为空');
        $this->login();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $this->login();
        $this->log('登录成功 cookie:' . $this->cookie);
        $this->getMain();

        if ($config['type'] == '1' && !empty($config['domains'])) {
            $domains = explode("\n", $config['domains']);
            $success = 0;
            $errmsg = null;
            foreach ($domains as $domain) {
                $domain = trim($domain);
                if (empty($domain)) continue;
                try {
                    $this->deployDomain($domain, $fullchain, $privatekey);
                    $this->log("域名 {$domain} 证书部署成功");
                    $success++;
                } catch (Exception $e) {
                    $errmsg = $e->getMessage();
                    $this->log("域名 {$domain} 证书部署失败：" . $errmsg);
                }
            }
            if ($success == 0) {
                throw new Exception($errmsg ? $errmsg : '要部署的域名不存在');
            }
        } else {
            $this->deployAccount($fullchain, $privatekey);
            $this->log("账号级SSL证书部署成功");
        }
    }

    private function deployDomain($domain, $fullchain, $privatekey)
    {
        $path = '/vhost/?c=ssl&a=domainSsl';
        $post = [
            'domain' => $domain,
            'certificate' => $fullchain,
            'certificate_key' => $privatekey,
        ];
        $response = http_request($this->url . $path, http_build_query($post), null, $this->cookie, null, $this->proxy);
        if (strpos($response['body'], '成功')) {
            return true;
        } elseif (preg_match('/alert\(\'(.*?)\'\)/i', $response['body'], $match)) {
            throw new Exception(htmlspecialchars($match[1]));
        } elseif (strlen($response['body']) > 3 && strlen($response['body']) < 50) {
            throw new Exception(htmlspecialchars($response['body']));
        } else {
            throw new Exception('原因未知(httpCode=' . $response['code'] . ')');
        }
    }

    private function deployAccount($fullchain, $privatekey)
    {
        $path = '/vhost/?c=ssl&a=ssl';
        $post = [
            'certificate' => $fullchain,
            'certificate_key' => $privatekey,
        ];
        $response = http_request($this->url . $path, http_build_query($post), null, $this->cookie, null, $this->proxy);
        if (strpos($response['body'], '成功')) {
            return true;
        } elseif (preg_match('/alert\(\'(.*?)\'\)/i', $response['body'], $match)) {
            throw new Exception(htmlspecialchars($match[1]));
        } elseif (strlen($response['body']) > 3 && strlen($response['body']) < 50) {
            throw new Exception(htmlspecialchars($response['body']));
        } else {
            throw new Exception('原因未知(httpCode=' . $response['code'] . ')');
        }
    }

    private function login()
    {
        if ($this->auth == '1') {
            return $this->loginBySkey();
        } else {
            return $this->loginByPwd();
        }
    }

    private function loginBySkey()
    {
        $url = $this->url . '/vhost/index.php?c=sso&a=hello&url=' . urlencode($this->url . '/index.php?');
        $response = http_request($url, null, null, null, null, $this->proxy);
        if ($response['code'] == 302 && !empty($response['redirect_url'])) {
            $cookie = '';
            if (isset($response['headers']['Set-Cookie'])) {
                foreach ($response['headers']['Set-Cookie'] as $val) {
                    $arr = explode('=', $val);
                    if ($arr[1] == '' || $arr[1] == 'deleted') continue;
                    $cookie .= $val . '; ';
                }
                $query = parse_url($response['redirect_url'], PHP_URL_QUERY);
                parse_str($query, $params);
                if (isset($params['r'])) {
                    $sess_key = $params['r'];
                    $this->loginBySkey2($cookie, $sess_key);
                    $this->cookie = $cookie;
                    return true;
                } else {
                    throw new Exception('获取SSO凭据失败，sess_key获取失败');
                }
            } else {
                throw new Exception('获取SSO凭据失败，获取cookie失败');
            }
        } elseif (strlen($response['body']) > 3 && strlen($response['body']) < 50) {
            throw new Exception('获取SSO凭据失败 (' . htmlspecialchars($response['body']) . ')');
        } else {
            throw new Exception('获取SSO凭据失败 (httpCode=' . $response['code'] . ')');
        }
    }

    private function loginBySkey2($cookie, $sess_key)
    {
        $s = md5($sess_key . $this->username . $sess_key . $this->skey);
        $url = $this->url . '/vhost/index.php?c=sso&a=login&name=' . $this->username . '&r=' . $sess_key . '&s=' . $s;
        $response = http_request($url, null, null, $cookie, null, $this->proxy);
        if ($response['code'] == 302) {
            return true;
        } elseif (strlen($response['body']) > 3 && strlen($response['body']) < 50) {
            throw new Exception('SSO登录失败 (' . htmlspecialchars($response['body']) . ')');
        } else {
            throw new Exception('SSO登录失败 (httpCode=' . $response['code'] . ')');
        }
    }

    private function loginByPwd()
    {
        $referer = $this->url . '/vhost/index.php?c=session&a=loginForm';
        $url = $this->url . '/vhost/index.php?c=session&a=login';
        $post = [
            'username' => $this->username,
            'passwd' => $this->password,
        ];
        $response = http_request($url, http_build_query($post), $referer, null, null, $this->proxy);
        if ($response['code'] == 302) {
            $cookie = '';
            if (isset($response['headers']['Set-Cookie'])) {
                foreach ($response['headers']['Set-Cookie'] as $val) {
                    $arr = explode('=', $val);
                    if ($arr[1] == '' || $arr[1] == 'deleted') continue;
                    $cookie .= $val . '; ';
                }
                $this->cookie = $cookie;
                return true;
            } else {
                throw new Exception('登录失败，获取cookie失败');
            }
        } elseif (strpos($response['body'], '验证码错误')) {
            throw new Exception('登录失败，需输入验证码');
        } elseif (strpos($response['body'], '密码错误')) {
            throw new Exception('登录失败，用户名或密码错误');
        } else {
            throw new Exception('登录失败 (httpCode=' . $response['code'] . ')');
        }
    }

    private function getMain()
    {
        $path = '/vhost/';
        http_request($this->url . $path, null, null, $this->cookie, null, $this->proxy);
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
