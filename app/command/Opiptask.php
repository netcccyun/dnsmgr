<?php

declare (strict_types=1);

namespace app\command;

use Exception;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Config;
use app\lib\OptimizeService;
use app\model\Config as ConfigModel;

class Opiptask extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('opiptask')
            ->setDescription('CF优选IP任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $res = ConfigModel::cache('configs', 0)->column('value', 'key');
        Config::set($res, 'sys');

        (new OptimizeService())->execute();
    }
}
