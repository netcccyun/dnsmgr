<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;
use think\facade\Config;
use app\service\EoDnsSyncService;

class Eosync extends Command
{
    protected function configure()
    {
        $this->setName('eosync')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只读预览，不写入任何记录')
            ->addOption('force', null, Option::VALUE_NONE, '忽略「启用开关」和「同步间隔」限制')
            ->setDescription('腾讯云EO 加速域名 → CNAME 记录自动同步');
    }

    protected function execute(Input $input, Output $output)
    {
        $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
        Config::set($res, 'sys');

        $dryRun = (bool)$input->getOption('dry-run');
        $force  = (bool)$input->getOption('force');
        $service = new EoDnsSyncService($dryRun);

        if ($dryRun) {
            $items = $service->buildPlan();
            $output->writeln('---- 只读预览（共' . count($items) . '条）----');
            foreach ($items as $item) {
                $output->writeln(sprintf(
                    '[%s] %s %s -> %s  %s',
                    $item['action'],
                    isset($item['fqdn']) && $item['fqdn'] !== '' ? $item['fqdn'] : $item['domain'],
                    isset($item['type']) && $item['type'] !== '' ? $item['type'] : '-',
                    isset($item['cname']) && $item['cname'] !== '' ? $item['cname'] : '-',
                    isset($item['message']) ? $item['message'] : ''
                ));
            }
            return 0;
        }

        $result = $force ? $service->run() : $service->autoRun(false);
        if ($result === false) {
            $output->writeln('已跳过：功能未启用或未到同步间隔（可用 --force 强制执行）');
            return 0;
        }
        $stat = $result['stat'];
        $output->writeln('同步完成：' . json_encode($stat, JSON_UNESCAPED_UNICODE));
        return $stat['error'] > 0 ? 1 : 0;
    }
}
