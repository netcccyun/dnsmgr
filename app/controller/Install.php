<?php

namespace app\controller;

use PDO;
use Exception;
use app\BaseController;
use think\facade\Cache;
use think\facade\Request;
use think\facade\View;
use think\facade\Db;

class Install extends BaseController
{
    public function index()
    {
        $dbconfig = '0';
        if (file_exists(app()->getRootPath() . '.env')) {
            if (checkTableExists('config') || checkTableExists('user') || checkTableExists('domain')) {
                return '当前已经安装成功，如果需要重新安装，请手动删除根目录.env文件';
            } else {
                $dbconfig = '1';
            }
        }
        if (Request::isPost()) {
            if ($dbconfig == '1') {
                $admin_username = input('post.admin_username', null, 'trim');
                $admin_password = input('post.admin_password', null, 'trim');

                if (!$admin_username || !$admin_password) {
                    return json(['code' => 0, 'msg' => '必填项不能为空']);
                }

                $driver = env('database.driver', 'mysql');
                $prefix = env('database.prefix', 'dnsmgr_');
                $sqls = $this->loadInstallSqls($driver);
                $sqls = array_merge($sqls, $this->buildSeedSqls($driver, $prefix, $admin_username, $admin_password));

                $success = 0;
                $errorMsg = null;
                foreach ($sqls as $value) {
                    $value = trim($value);
                    if (empty($value)) continue;
                    $value = str_replace('dnsmgr_', $prefix, $value);
                    try {
                        if (Db::execute($value) === false) {
                            $errorMsg .= Db::getLastSql() . "\n";
                        } else {
                            $success++;
                        }
                    } catch (Exception $e) {
                        $errorMsg .= $e->getMessage() . "\n";
                    }
                }
                if (empty($errorMsg)) {
                    Cache::clear();
                    return json(['code' => 1, 'msg' => '安装完成！成功执行SQL语句' . $success . '条']);
                } else {
                    return json(['code' => 0, 'msg' => $errorMsg]);
                }
            } else {
                $db_type = input('post.db_type', 'mysql', 'trim');
                if (!in_array($db_type, ['mysql', 'pgsql'], true)) {
                    return json(['code' => 0, 'msg' => '不支持的数据库类型']);
                }
                $db_host = input('post.mysql_host', null, 'trim');
                $db_port = intval(input('post.mysql_port', $db_type === 'pgsql' ? '5432' : '3306'));
                $db_user = input('post.mysql_user', null, 'trim');
                $db_pwd = input('post.mysql_pwd', null, 'trim');
                $db_name = input('post.mysql_name', null, 'trim');
                $db_prefix = input('post.mysql_prefix', 'dnsmgr_', 'trim');
                $admin_username = input('post.admin_username', null, 'trim');
                $admin_password = input('post.admin_password', null, 'trim');

                if (!$db_host || !$db_user || !$db_pwd || !$db_name || !$admin_username || !$admin_password) {
                    return json(['code' => 0, 'msg' => '必填项不能为空']);
                }

                $configData = file_get_contents(app()->getRootPath() . '.example.env');
                $configData = str_replace(
                    ['{dbdriver}', '{dbtype}', '{dbhost}', '{dbname}', '{dbuser}', '{dbpwd}', '{dbport}', '{dbprefix}'],
                    [$db_type, $db_type, $db_host, $db_name, $db_user, $db_pwd, $db_port, $db_prefix],
                    $configData
                );

                try {
                    if ($db_type === 'pgsql') {
                        $dsn = "pgsql:host={$db_host};port={$db_port};dbname={$db_name}";
                    } else {
                        $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name}";
                    }
                    $DB = new PDO($dsn, $db_user, $db_pwd);
                } catch (Exception $e) {
                    if ($e->getCode() == 2002) {
                        $errorMsg = '连接数据库失败：数据库地址填写错误！';
                    } elseif ($e->getCode() == 1045 || $e->getCode() == '28P01' || $e->getCode() == '28000') {
                        $errorMsg = '连接数据库失败：数据库用户名或密码填写错误！';
                    } elseif ($e->getCode() == 1049 || $e->getCode() == '3D000') {
                        $errorMsg = '连接数据库失败：数据库名不存在！';
                    } else {
                        $errorMsg = '连接数据库失败：' . $e->getMessage();
                    }
                    return json(['code' => 0, 'msg' => $errorMsg]);
                }
                $DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                if ($db_type === 'mysql') {
                    $DB->exec("set sql_mode = ''");
                    $DB->exec("set names utf8");
                } else {
                    $DB->exec("set client_encoding to 'UTF8'");
                }

                $sqls = $this->loadInstallSqls($db_type);
                $sqls = array_merge($sqls, $this->buildSeedSqls($db_type, $db_prefix, $admin_username, $admin_password));

                $success = 0;
                $errorMsg = null;
                foreach ($sqls as $value) {
                    $value = trim($value);
                    if (empty($value)) continue;
                    $value = str_replace('dnsmgr_', $db_prefix, $value);
                    if ($DB->exec($value) === false) {
                        $dberror = $DB->errorInfo();
                        $errorMsg .= $dberror[2] . "\n";
                    } else {
                        $success++;
                    }
                }
                if (empty($errorMsg)) {
                    if (!file_put_contents(app()->getRootPath() . '.env', $configData)) {
                        return json(['code' => 0, 'msg' => '保存失败，请确保网站根目录有写入权限']);
                    }
                    Cache::clear();
                    return json(['code' => 1, 'msg' => '安装完成！成功执行SQL语句' . $success . '条']);
                } else {
                    return json(['code' => 0, 'msg' => $errorMsg]);
                }
            }
        }
        View::assign('dbconfig', $dbconfig);
        return view();
    }

    private function loadInstallSqls(string $driver): array
    {
        $file = $driver === 'pgsql' ? 'sql/install.pgsql.sql' : 'sql/install.sql';
        $content = file_get_contents(app()->getAppPath() . $file);
        return explode(';', $content);
    }

    private function buildSeedSqls(string $driver, string $prefix, string $admin_username, string $admin_password): array
    {
        $password = password_hash($admin_password, PASSWORD_DEFAULT);
        $sysKey = random(16);
        $userName = addslashes($admin_username);

        if ($driver === 'pgsql') {
            return [
                "INSERT INTO {$prefix}config (\"key\", value) VALUES ('sys_key', '{$sysKey}') ON CONFLICT (\"key\") DO UPDATE SET value = EXCLUDED.value",
                "INSERT INTO {$prefix}user (username, password, level, regtime, lasttime, status) VALUES ('{$userName}', '{$password}', 2, NOW(), NOW(), 1)",
            ];
        }
        return [
            "REPLACE INTO `{$prefix}config` VALUES ('sys_key', '{$sysKey}')",
            "INSERT INTO `{$prefix}user` (`username`,`password`,`level`,`regtime`,`lasttime`,`status`) VALUES ('{$userName}', '{$password}', 2, NOW(), NOW(), 1)",
        ];
    }
}
