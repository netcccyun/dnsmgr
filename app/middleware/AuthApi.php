<?php
declare (strict_types=1);

namespace app\middleware;

use think\facade\Db;

class AuthApi
{
    public function handle($request, \Closure $next)
    {
        $uid = input('post.uid/d');
        $timestamp = input('post.timestamp');
        $sign = input('post.sign');
        if(!$uid || empty($timestamp) || empty($sign)){
            return json(['code'=>-1, 'msg'=>'认证参数不能为空'])->code(403);
        }
        if($timestamp < time()-300 || $timestamp > time()+300){
            return json(['code'=>-1, 'msg'=>'时间戳不合法'])->code(403);
        }
        $user = Db::name('user')->where('id', $uid)->find();
        if(!$user) return json(['code'=>-1, 'msg'=>'用户不存在'])->code(403);
        if($user['status'] == 0) return json(['code'=>-1, 'msg'=>'该用户已被封禁'])->code(403);
        if($user['is_api'] == 0) return json(['code'=>-1, 'msg'=>'该用户未开启API权限'])->code(403);
        if(md5($uid.$timestamp.$user['apikey']) !== $sign){
            return json(['code'=>-1, 'msg'=>'签名错误'])->code(403);
        }

        $user['type'] = 'user';
        $user['permission'] = [];
        if($user['level'] == 1){
            $user['permission'] = Db::name('permission')->where('uid', $uid)->column('domain');
        }

        $request->islogin = true;
        $request->isApi = true;
        $request->user = $user;
        return $next($request);
    }
}
