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

class Reset extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('reset')
            ->addArgument('type', Argument::REQUIRED, '操作类型,pwd:重置密码,totp:关闭TOTP')
            ->addArgument('username', Argument::REQUIRED, '用户名')
            ->addArgument('password', Argument::OPTIONAL, '密码')
            ->setDescription('重置密码');
    }

    protected function execute(Input $input, Output $output)
    {
        $type = trim($input->getArgument('type'));
        $username = trim($input->getArgument('username'));
        $user = Db::name('user')->where('username', $username)->find();
        if (!$user) {
            $output->writeln('用户 ' . $username . ' 不存在');
            return;
        }
        if ($type == 'pwd') {
            $password = $input->getArgument('password');
            if (empty($password)) $password = '123456';
            Db::name('user')->where('id', $user['id'])->update(['password' => password_hash($password, PASSWORD_DEFAULT)]);
            $output->writeln('用户 ' . $username . ' 密码重置成功');
        } elseif ($type == 'totp') {
            Db::name('user')->where('id', $user['id'])->update(['totp_open' => 0, 'totp_secret' => null]);
            $output->writeln('用户 ' . $username . ' TOTP关闭成功');
        }
    }
}
