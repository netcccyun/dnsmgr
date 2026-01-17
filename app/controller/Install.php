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

                $sqls = file_get_contents(app()->getAppPath() . 'sql/install.sql');
                $sqls = explode(';', $sqls);
                $mysql_prefix = env('database.prefix', 'dnsmgr_');

                $password = password_hash($admin_password, PASSWORD_DEFAULT);
                $sqls[] = "REPLACE INTO `" . $mysql_prefix . "config` VALUES ('sys_key', '" . random(16) . "')";
                $sqls[] = "INSERT INTO `" . $mysql_prefix . "user` (`username`,`password`,`level`,`regtime`,`lasttime`,`status`) VALUES ('" . addslashes($admin_username) . "', '$password', 2, NOW(), NOW(), 1)";

                $success = 0;
                $error = 0;
                $errorMsg = null;
                foreach ($sqls as $value) {
                    $value = trim($value);
                    if (empty($value)) continue;
                    $value = str_replace('dnsmgr_', $mysql_prefix, $value);
                    if (Db::execute($value) === false) {
                        $error++;
                        $dberror = Db::getErrorInfo();
                        $errorMsg .= $dberror . "\n";
                    } else {
                        $success++;
                    }
                }
                if (empty($errorMsg)) {
                    Cache::clear();
                    return json(['code' => 1, 'msg' => '安装完成！成功执行SQL语句' . $success . '条']);
                } else {
                    return json(['code' => 0, 'msg' => $errorMsg]);
                }
            } else {
                $mysql_host = input('post.mysql_host', null, 'trim');
                $mysql_port = intval(input('post.mysql_port', '3306'));
                $mysql_user = input('post.mysql_user', null, 'trim');
                $mysql_pwd = input('post.mysql_pwd', null, 'trim');
                $mysql_name = input('post.mysql_name', null, 'trim');
                $mysql_prefix = input('post.mysql_prefix', 'cloud_', 'trim');
                $admin_username = input('post.admin_username', null, 'trim');
                $admin_password = input('post.admin_password', null, 'trim');

                if (!$mysql_host || !$mysql_user || !$mysql_pwd || !$mysql_name || !$admin_username || !$admin_password) {
                    return json(['code' => 0, 'msg' => '必填项不能为空']);
                }

                $configData = file_get_contents(app()->getRootPath() . '.example.env');
                $configData = str_replace(['{dbhost}', '{dbname}', '{dbuser}', '{dbpwd}', '{dbport}', '{dbprefix}'], [$mysql_host, $mysql_name, $mysql_user, $mysql_pwd, $mysql_port, $mysql_prefix], $configData);

                try {
                    $DB = new PDO("mysql:host=" . $mysql_host . ";dbname=" . $mysql_name . ";port=" . $mysql_port, $mysql_user, $mysql_pwd);
                } catch (Exception $e) {
                    if ($e->getCode() == 2002) {
                        $errorMsg = '连接数据库失败：数据库地址填写错误！';
                    } elseif ($e->getCode() == 1045) {
                        $errorMsg = '连接数据库失败：数据库用户名或密码填写错误！';
                    } elseif ($e->getCode() == 1049) {
                        $errorMsg = '连接数据库失败：数据库名不存在！';
                    } else {
                        $errorMsg = '连接数据库失败：' . $e->getMessage();
                    }
                    return json(['code' => 0, 'msg' => $errorMsg]);
                }
                $DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
                $DB->exec("set sql_mode = ''");
                $DB->exec("set names utf8");

                $sqls = file_get_contents(app()->getAppPath() . 'sql/install.sql');
                $sqls = explode(';', $sqls);

                $password = password_hash($admin_password, PASSWORD_DEFAULT);
                $sqls[] = "REPLACE INTO `" . $mysql_prefix . "config` VALUES ('sys_key', '" . random(16) . "')";
                $sqls[] = "INSERT INTO `" . $mysql_prefix . "user` (`username`,`password`,`level`,`regtime`,`lasttime`,`status`) VALUES ('" . addslashes($admin_username) . "', '$password', 2, NOW(), NOW(), 1)";

                $success = 0;
                $error = 0;
                $errorMsg = null;
                foreach ($sqls as $value) {
                    $value = trim($value);
                    if (empty($value)) continue;
                    $value = str_replace('dnsmgr_', $mysql_prefix, $value);
                    if ($DB->exec($value) === false) {
                        $error++;
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
}
