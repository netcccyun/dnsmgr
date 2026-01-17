<?php

declare (strict_types=1);

namespace app\middleware;

use Exception;
use think\facade\Db;
use think\facade\Config;
use think\facade\Cache;

class LoadConfig
{
    /**
     * 处理请求
     *
     * @param \think\Request $request
     * @param \Closure       $next
     * @return Response
     */
    public function handle($request, \Closure $next)
    {
        if (!file_exists(app()->getRootPath().'.env')) {
            if (strpos($request->url(), '/install') === false) {
                return redirect((string)url('/install'))->header([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                    'Pragma' => 'no-cache',
                ]);
            } else {
                return $next($request);
            }
        }
        if (!checkTableExists('config') && !checkTableExists('user')) {
            if (strpos($request->url(), '/install') === false) {
                return redirect((string)url('/install'))->header([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                    'Pragma' => 'no-cache',
                ]);
            } else {
                return $next($request);
            }
        }

        try {
            $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
            if (empty($res['sys_key']) && !empty(env('app.sys_key'))) {
                config_set('sys_key', env('app.sys_key'));
                Cache::delete('configs');
                $res['sys_key'] = env('app.sys_key');
            }
            Config::set($res, 'sys');
        } catch (Exception $e) {
            if (!strpos($e->getMessage(), 'doesn\'t exist')) {
                throw $e;
            }
        }

        $request->isApi = false;

        return $next($request)->header([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
