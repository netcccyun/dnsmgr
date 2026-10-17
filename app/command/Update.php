<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;
use think\facade\Config;
use app\service\UpdateService;

/**
 * 在线更新执行命令：php think update:run {token}
 */
class Update extends Command
{
    protected function configure()
    {
        $this->setName('update:run')
            ->addArgument('token', Argument::REQUIRED, '更新任务token')
            ->setDescription('执行在线更新任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $token = trim((string)$input->getArgument('token'));
        // 加载数据库配置
        try {
            $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
            Config::set($res, 'sys');
        } catch (\Throwable $e) {
            $output->writeln('[Error] 加载配置失败：' . $e->getMessage());
            return;
        }

        $service = new UpdateService();
        $service->runTask($token);

        $progress = $service->getProgress($token);
        $output->writeln('[' . ($progress['status'] ?? 'unknown') . '] ' . ($progress['message'] ?? ''));
    }
}
