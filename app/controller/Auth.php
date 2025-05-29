<?php

namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Db;

class Auth extends BaseController
{

    public function login()
    {
        $login_limit_count = 5; //登录失败次数
        $login_limit_file = app()->getRuntimePath() . '@login.lock';

        if ($this->request->islogin) {
            return redirect('/');
        }

        if ($this->request->isAjax()) {
            $username = input('post.username', null, 'trim');
            $password = input('post.password', null, 'trim');
            $code = input('post.code', null, 'trim');

            if (empty($username) || empty($password)) {
                return json(['code' => -1, 'msg' => '用户名或密码不能为空']);
            }
            if (config_get('vcode', '1') == '1' && !captcha_check($code)) {
                return json(['code' => -1, 'msg' => '验证码错误', 'vcode' => 1]);
            }
            if (file_exists($login_limit_file)) {
                $login_limit = unserialize(file_get_contents($login_limit_file));
                if ($login_limit['count'] >= $login_limit_count && $login_limit['time'] > time() - 7200) {
                    return json(['code' => -1, 'msg' => '多次登录失败，暂时禁止登录。可删除/runtime/@login.lock文件解除限制', 'vcode' => 1]);
                }
            }
            $user = Db::name('user')->where('username', $username)->find();
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] == 0) return json(['code' => -1, 'msg' => '此用户已被封禁', 'vcode' => 1]);
                if (isset($user['totp_open']) && $user['totp_open'] == 1 && !empty($user['totp_secret'])) {
                    session('pre_login_user', $user['id']);
                    if (file_exists($login_limit_file)) {
                        unlink($login_limit_file);
                    }
                    return json(['code' => -1, 'msg' => '需要验证动态口令', 'vcode' => 2]);
                }
                $this->loginUser($user);
                if (file_exists($login_limit_file)) {
                    unlink($login_limit_file);
                }
                return json(['code' => 0]);
            } else {
                if ($user) {
                    Db::name('log')->insert(['uid' => $user['id'], 'action' => '登录失败', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
                    if (isset($user['totp_open']) && $user['totp_open'] == 1 && !empty($user['totp_secret'])) {
                        return json(['code' => -1, 'msg' => '用户名或密码错误', 'vcode' => 1]);
                    }
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

    public function totp()
    {
        $uid = session('pre_login_user');
        if (empty($uid)) return json(['code' => -1, 'msg' => '请重新登录']);
        $code = input('post.code');
        if (empty($code)) return json(['code' => -1, 'msg' => '请输入动态口令']);
        $user = Db::name('user')->where('id', $uid)->find();
        if (!$user) return json(['code' => -1, 'msg' => '用户不存在']);
        if ($user['totp_open'] == 0 || empty($user['totp_secret'])) return json(['code' => -1, 'msg' => '未开启TOTP二次验证']);
        try {
            $totp = \app\lib\TOTP::create($user['totp_secret']);
            if (!$totp->verify($code)) {
                return json(['code' => -1, 'msg' => '动态口令错误']);
            }
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
        $this->loginUser($user);
        session('pre_login_user', null);
        return json(['code' => 0]);
    }

    public function logout()
    {
        cookie('user_token', null);
        return redirect('/login');
    }

    public function quicklogin()
    {
        $domain = input('get.domain', null, 'trim');
        $timestamp = input('get.timestamp', null, 'trim');
        $token = input('get.token', null, 'trim');
        $sign = input('get.sign', null, 'trim');
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
        $row = Db::name('domain')->where('name', $domain)->find();
        if (!$row) {
            return $this->alert('error', '该域名不存在');
        }
        if (!$row['is_sso']) {
            return $this->alert('error', '该域名不支持快捷登录');
        }

        $this->loginDomain($row);
        return redirect('/record/' . $row['id']);
    }

    private function loginUser($user)
    {
        Db::name('log')->insert(['uid' => $user['id'], 'action' => '登录后台', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
        DB::name('user')->where('id', $user['id'])->update(['lasttime' => date("Y-m-d H:i:s")]);
        $session = md5($user['id'] . $user['password']);
        $expiretime = time() + 2562000;
        $token = authcode("user\t{$user['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true]);
    }

    private function loginDomain($row)
    {
        Db::name('log')->insert(['uid' => 0, 'action' => '域名快捷登录', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s"), 'domain' => $row['name']]);
        $session = md5($row['id'] . $row['name']);
        $expiretime = time() + 2562000;
        $token = authcode("domain\t{$row['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true]);
    }

    public function verifycode()
    {
        return captcha();
    }
}
