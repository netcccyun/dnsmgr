<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;

class Reset extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('reset')
            ->addArgument('type', Argument::REQUIRED, '操作类型,pwd:重置密码,totp:关闭TOTP,oauth_disable_password:恢复密码登录')
            ->addArgument('username', Argument::OPTIONAL, '用户名(pwd/totp必需)')
            ->addArgument('password', Argument::OPTIONAL, '新密码(仅pwd)')
            ->setDescription('重置管理命令');
    }

    protected function execute(Input $input, Output $output)
    {
        $type = trim($input->getArgument('type'));

        if ($type == 'oauth_disable_password') {
            config_set('oauth_disable_password', '0');
            \think\facade\Cache::delete('configs');
            $output->writeln('密码登录已恢复！现在可以使用用户名和密码登录后台。');
            return Command::SUCCESS;
        }

        $username = trim($input->getArgument('username'));
        if (empty($username)) {
            $output->writeln('用户名不能为空');
            return Command::FAILURE;
        }
        $user = Db::name('user')->where('username', $username)->find();
        if (!$user) {
            $output->writeln('用户 ' . $username . ' 不存在');
            return Command::FAILURE;
        }
        if ($type == 'pwd') {
            $password = $input->getArgument('password');
            if (empty($password)) $password = '123456';
            Db::name('user')->where('id', $user['id'])->update(['password' => password_hash($password, PASSWORD_DEFAULT)]);
            $output->writeln('用户 ' . $username . ' 密码重置成功');
            return Command::SUCCESS;
        } elseif ($type == 'totp') {
            Db::name('user')->where('id', $user['id'])->update(['totp_open' => 0, 'totp_secret' => null]);
            $output->writeln('用户 ' . $username . ' TOTP关闭成功');
            return Command::SUCCESS;
        }

        $output->writeln('未知操作类型：' . $type);
        return Command::FAILURE;
    }
}
