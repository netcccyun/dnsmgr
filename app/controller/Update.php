<?php

namespace app\controller;

use app\BaseController;
use Exception;
use app\service\UpdateService;

/**
 * 在线更新
 */
class Update extends BaseController
{
    /** 获取更新服务实例 */
    private function service(): UpdateService
    {
        return new UpdateService();
    }

    /**
     * 检查最新版本
     */
    public function check()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        try {
            $data = $this->service()->check(true);
            return json(['code' => 0, 'msg' => 'succ', 'data' => $data]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 创建更新任务并尝试后台执行
     */
    public function start()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $service = $this->service();

        // 写权限检测
        $bad = $service->checkWritable();
        if (!empty($bad)) {
            return json(['code' => -1, 'msg' => '以下目录无写入权限：' . implode('、', $bad) . '；Docker/只读环境请挂载可写目录后重试']);
        }
        if (!class_exists('ZipArchive')) {
            return json(['code' => -1, 'msg' => '服务器未安装 zip 扩展，无法解压更新包']);
        }

        // 预检查最新版本（失败则直接返回，避免生成无效任务）
        try {
            $latest = $service->check(false);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }

        $token = $service->createTask();
        $service->setProgress(array_merge($service->getProgress($token), [
            'to'      => $latest['latest'],
            'message' => '准备更新到 ' . $latest['latest'] . '…',
        ]));

        if ($service->launchBackground($token)) {
            return json(['code' => 0, 'msg' => 'succ', 'data' => ['token' => $token, 'mode' => 'async', 'to' => $latest['latest']]]);
        }

        // 后台不可用：同步执行
        $service->runTask($token);
        $progress = $service->getProgress($token);
        if (($progress['status'] ?? '') === 'failed') {
            return json(['code' => -1, 'msg' => $progress['error'] ?? '更新失败']);
        }
        return json(['code' => 0, 'msg' => 'succ', 'data' => ['token' => $token, 'mode' => 'sync', 'to' => $latest['latest']]]);
    }

    /**
     * 查询更新进度
     */
    public function progress()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        $token = trim((string)input('get.token', '', 'trim'));
        if ($token === '') {
            return json(['code' => -1, 'msg' => '参数错误']);
        }
        $progress = $this->service()->getProgress($token);
        if (empty($progress)) {
            return json(['code' => -1, 'msg' => '任务不存在或已过期']);
        }
        return json(['code' => 0, 'msg' => 'succ', 'data' => $progress]);
    }

    /**
     * 历史版本备份列表
     */
    public function history()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        return json([
            'code' => 0,
            'msg'  => 'succ',
            'data' => [
                'current' => UpdateService::getCurrentRelease(),
                'list'    => $this->service()->listBackups(),
            ],
        ]);
    }
}
