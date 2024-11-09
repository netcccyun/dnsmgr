<?php

namespace app\controller;

use app\BaseController;
use app\model\Log;
use app\model\User;
use app\model\Domain as DomainModel;
use think\captcha\facade\Captcha;
use think\response;

class Auth extends BaseController
{
    public function login(): response
    {
        $login_limit_count = 5;//登录失败次数
        $login_limit_file = app()->getRuntimePath() . '@login.lock';

        if ($this->request->islogin) {
            return redirect('/');
        }

        if ($this->request->isAjax()) {
            $username = $this->request->post('username', null, 'trim');
            $password = $this->request->post('password', null, 'trim');
            $code = $this->request->post('code', null, 'trim');

            if (empty($username) || empty($password)) {
                return json(['code' => -1, 'msg' => '用户名或密码不能为空']);
            }
            if (!Captcha::check($code)) {
                return json(['code' => -1, 'msg' => '验证码错误', 'vcode' => 1]);
            }
            if (file_exists($login_limit_file)) {
                $login_limit = unserialize(file_get_contents($login_limit_file));
                if ($login_limit['count'] >= $login_limit_count && $login_limit['time'] > time() - 7200) {
                    return json(['code' => -1, 'msg' => '多次登录失败，暂时禁止登录。可删除/runtime/@login.lock文件解除限制', 'vcode' => 1]);
                }
            }
            $user = User::where('username', $username)->find();
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] == 0) {
                    return json(['code' => -1, 'msg' => '此用户已被封禁', 'vcode' => 1]);
                }
                Log::insert(['uid' => $user['id'], 'action' => '登录后台', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
                User::where('id', $user['id'])->update(['lasttime' => date("Y-m-d H:i:s")]);
                $session = md5($user['id'] . $user['password']);
                $expiretime = time() + 2562000;
                $token = authcode("user\t{$user['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
                cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true]);
                if (file_exists($login_limit_file)) {
                    unlink($login_limit_file);
                }
                return json(['code' => 0]);
            } else {
                if ($user) {
                    Log::insert(['uid' => $user['id'], 'action' => '登录失败', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
                }
                if (!file_exists($login_limit_file)) {
                    $login_limit = ['count' => 0, 'time' => 0];
                }
                $login_limit['count']++;
                $login_limit['time'] = time();
                file_put_contents($login_limit_file, serialize($login_limit));
                $retry_times = $login_limit_count - $login_limit['count'];
                if ($retry_times == 0) {
                    return json(['code' => -1, 'msg' => '多次登录失败，暂时禁止登录。可删除/runtime/@login.lock文件解除限制', 'vcode' => 1]);
                } else {
                    return json(['code' => -1, 'msg' => '用户名或密码错误，你还可以尝试' . $retry_times . '次', 'vcode' => 1]);
                }
            }
        }

        return view();
    }

    public function logout(): response
    {
        cookie('user_token', null);
        return redirect('/login');
    }

    public function quicklogin(): response
    {
        $domain = $this->request->get('domain', null, 'trim');
        $timestamp = $this->request->get('timestamp', null, 'trim');
        $token = $this->request->get('token', null, 'trim');
        $sign = $this->request->get('sign', null, 'trim');
        if (empty($domain) || empty($timestamp) || empty($token) || empty($sign)) {
            return $this->alert('error', '参数错误');
        }
        if ($timestamp < time() - 300 || $timestamp > time() + 300) {
            return $this->alert('error', '时间戳无效');
        }
        if (md5(config_get('sys_key') . $domain . $timestamp . $token . config_get('sys_key')) !== $sign) {
            return $this->alert('error', '签名错误');
        }
        if ($token != cache('quicklogin_' . $domain)) {
            return $this->alert('error', 'Token无效');
        }
        $row = DomainModel::where('name', $domain)->find();
        if (!$row) {
            return $this->alert('error', '该域名不存在');
        }
        if (!$row['is_sso']) {
            return $this->alert('error', '该域名不支持快捷登录');
        }

        Log::insert(['uid' => 0, 'action' => '域名快捷登录', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s"), 'domain' => $domain]);

        $session = md5($row['id'] . $row['name']);
        $expiretime = time() + 2562000;
        $token = authcode("domain\t{$row['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true]);
        return redirect('/record/' . $row['id']);
    }

    public function verifycode()
    {
        return Captcha::create();
    }
}
