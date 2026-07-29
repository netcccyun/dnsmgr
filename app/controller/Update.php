<?php

namespace app\controller;

use app\BaseController;
use app\service\UpdateService;
use think\facade\View;

/**
 * 在线更新（独立控制器，避免被官方更新包覆盖 System.php 后失效）
 */
class Update extends BaseController
{
    public function index()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        View::assign('local_version', config('app.version'));
        View::assign('local_dbversion', config('app.dbversion'));
        View::assign('php_version', PHP_VERSION);
        View::assign('has_zip', class_exists(\ZipArchive::class) || strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        View::assign('writable', is_writable(app()->getRootPath()));
        return View::fetch();
    }

    public function check()
    {
        if (!checkPermission(2)) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        try {
            return json((new UpdateService())->check());
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '检查失败：' . $e->getMessage()]);
        }
    }

    public function apply()
    {
        if (!checkPermission(2)) {
            return json(['code' => -1, 'msg' => '无权限']);
        }
        if (!$this->request->isPost()) {
            return json(['code' => -1, 'msg' => '请求方式错误']);
        }
        $downloadUrl = input('post.download_url', '', 'trim');
        $force = input('post.force/d', 0) === 1;
        try {
            return json((new UpdateService())->apply($downloadUrl ?: null, $force));
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '更新失败：' . $e->getMessage()]);
        }
    }
}
