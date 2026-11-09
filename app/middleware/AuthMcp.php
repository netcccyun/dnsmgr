<?php

declare (strict_types=1);

namespace app\middleware;

use think\facade\Db;

class AuthMcp
{
    public function handle($request, \Closure $next)
    {
        $auth = trim((string)$request->header('authorization', ''));
        if (stripos($auth, 'Bearer ') !== 0) {
            return $this->deny('缺少认证信息，请使用 Authorization: Bearer <uid>:<apikey>');
        }
        $token = trim(substr($auth, 7));
        $parts = explode(':', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || $parts[1] === '') {
            return $this->deny('认证信息格式错误，应为 <uid>:<apikey>');
        }
        list($uid, $apikey) = $parts;

        $user = Db::name('user')->where('id', intval($uid))->find();
        if (!$user || empty($user['apikey']) || !hash_equals((string)$user['apikey'], $apikey)) {
            return $this->deny('用户不存在或API密钥错误');
        }
        if ($user['status'] == 0) {
            return $this->deny('该用户已被封禁');
        }
        if ($user['is_api'] == 0) {
            return $this->deny('该用户未开启API权限');
        }

        $user['type'] = 'user';
        $user['permission'] = [];
        if ($user['level'] == 1) {
            $user['permission'] = Db::name('permission')->where('uid', $user['id'])->column('domain');
        }

        $request->islogin = true;
        $request->isApi = true;
        $request->user = $user;
        return $next($request);
    }

    private function deny($msg)
    {
        return json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32001, 'message' => $msg],
        ])->code(401)->header(['WWW-Authenticate' => 'Bearer realm="dnsmgr-mcp"']);
    }
}
