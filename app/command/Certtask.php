<?php

declare(strict_types=1);

namespace app\command;

use Exception;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Config;
use app\service\OptimizeService;
use app\service\CertTaskService;
use app\service\ExpireNoticeService;

class Certtask extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('certtask')
            ->setDescription('SSL证书续签与部署、域名到期提醒、CF优选IP更新');
    }

    protected function execute(Input $input, Output $output)
    {
        $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
        Config::set($res, 'sys');

        $res = (new OptimizeService())->execute();
        if (!$res) {
            (new CertTaskService())->execute();
            (new ExpireNoticeService())->task();
        }
        echo 'done'.PHP_EOL;
    }
}
