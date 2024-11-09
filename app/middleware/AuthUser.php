<?php

declare (strict_types=1);

namespace app\middleware;

use think\facade\Db;

class AuthUser
{
    public function handle($request, \Closure $next)
    {
        $islogin = false;
        $cookie = cookie('user_token');
        $user = null;
        if ($cookie && config_get('sys_key')) {
            $token = authcode($cookie, 'DECODE', config_get('sys_key'));
            if ($token) {
                list($type, $uid, $sid, $expiretime) = explode("\t", $token);
                if ($type == 'user') {
                    $user = Db::name('user')->where('id', $uid)->find();
                    if ($user && $user['status'] == 1) {
                        $session = md5($user['id'].$user['password']);
                        if ($session == $sid && $expiretime > time()) {
                            $islogin = true;
                        }
                        $user['type'] = 'user';
                        $user['permission'] = [];
                        if ($user['level'] == 1) {
                            $user['permission'] = Db::name('permission')->where('uid', $uid)->column('domain');
                        }
                    }
                } elseif ($type == 'domain') {
                    $user = Db::name('domain')->where('id', $uid)->find();
                    if ($user && $user['is_sso'] == 1) {
                        $session = md5($user['id'].$user['name']);
                        if ($session == $sid && $expiretime > time()) {
                            $islogin = true;
                        }
                        $user['username'] = $user['name'];
                        $user['regtime'] = $user['addtime'];
                        $user['type'] = 'domain';
                        $user['level'] = 0;
                        $user['permission'] = [$user['name']];
                    }
                }
            }
        }
        $request->islogin = $islogin;
        $request->user = $user;
        return $next($request);
    }
}
