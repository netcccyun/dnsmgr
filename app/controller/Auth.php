<?php

namespace app\controller;

use app\BaseController;
use app\service\oauth\OAuthAuthService;
use app\service\oauth\OAuthProviderService;
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
            if (config_get('oauth_disable_password', '0') == '1') {
                return json(['code' => -1, 'msg' => '管理员已禁用密码登录，请使用第三方账号登录']);
            }
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
                    session('oauth_totp_pending', null);
                    session('totp_attempt', null);
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

        $providers = (new OAuthProviderService())->getEnabledProviders();
        \think\facade\View::assign('oauth_providers', $providers);
        \think\facade\View::assign('oauth_disable_password', config_get('oauth_disable_password', '0'));
        \think\facade\View::assign('show_totp', input('get.totp/d', 0) == 1 && session('pre_login_user'));

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
        if ($user['status'] == 0) {
            session('pre_login_user', null);
            session('oauth_totp_pending', null);
            session('totp_attempt', null);
            return json(['code' => -1, 'msg' => '此用户已被封禁']);
        }
        if ($user['totp_open'] == 0 || empty($user['totp_secret'])) return json(['code' => -1, 'msg' => '未开启TOTP二次验证']);
        $totpAttempt = session('totp_attempt') ?: ['count' => 0, 'time' => 0];
        if (!is_array($totpAttempt)) {
            $totpAttempt = ['count' => 0, 'time' => 0];
        }
        if ($totpAttempt['count'] >= 5 && $totpAttempt['time'] > time() - 600) {
            session('pre_login_user', null);
            session('oauth_totp_pending', null);
            session('totp_attempt', null);
            return json(['code' => -1, 'msg' => '动态口令错误次数过多，请重新登录']);
        }
        try {
            $totp = \app\lib\TOTP::create($user['totp_secret']);
            if (!$totp->verify($code)) {
                $totpAttempt['count']++;
                $totpAttempt['time'] = time();
                session('totp_attempt', $totpAttempt);
                return json(['code' => -1, 'msg' => '动态口令错误']);
            }
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
        try {
            $this->completePendingOauthLogin((int)$user['id']);
        } catch (Exception $e) {
            session('pre_login_user', null);
            session('oauth_totp_pending', null);
            session('totp_attempt', null);
            trace('OAuth TOTP binding refresh error: user_id=' . $user['id'] . ', message=' . $e->getMessage(), 'error');
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
        $this->regenerateSessionIdIfActive();
        $this->loginUser($user);
        session('pre_login_user', null);
        session('oauth_totp_pending', null);
        session('totp_attempt', null);
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

    private function completePendingOauthLogin(int $userId): void
    {
        $pending = session('oauth_totp_pending');
        if (!is_array($pending)) {
            return;
        }
        if ((int)($pending['user_id'] ?? 0) !== $userId) {
            throw new Exception('OAuth登录状态与当前二次验证用户不匹配，请重新登录');
        }
        if (empty($pending['provider']) || empty($pending['userInfo']) || empty($pending['tokenData'])) {
            throw new Exception('OAuth登录状态已失效，请重新登录');
        }
        (new OAuthAuthService())->updateLoginBinding($pending['provider'], $pending['userInfo'], $pending['tokenData']);
    }

    private function regenerateSessionIdIfActive(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function loginUser($user)
    {
        Db::name('log')->insert(['uid' => $user['id'], 'action' => '登录后台', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s")]);
        Db::name('user')->where('id', $user['id'])->update(['lasttime' => date("Y-m-d H:i:s")]);
        $session = md5($user['id'] . $user['password']);
        $expiretime = time() + 2562000;
        $token = authcode("user\t{$user['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true, 'samesite' => 'Lax', 'secure' => request()->isSsl()]);
    }

    private function loginDomain($row)
    {
        Db::name('log')->insert(['uid' => 0, 'action' => '域名快捷登录', 'data' => 'IP:' . $this->clientip, 'addtime' => date("Y-m-d H:i:s"), 'domain' => $row['name']]);
        $session = md5($row['id'] . $row['name']);
        $expiretime = time() + 2562000;
        $token = authcode("domain\t{$row['id']}\t{$session}\t{$expiretime}", 'ENCODE', config_get('sys_key'));
        cookie('user_token', $token, ['expire' => $expiretime, 'httponly' => true, 'samesite' => 'Lax', 'secure' => request()->isSsl()]);
    }

    public function verifycode()
    {
        return captcha();
    }
}
