<?php

namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;

class Index extends BaseController
{
    public function index()
    {
        if ($this->request->user['type'] == 'domain') {
            return redirect('/record/' . $this->request->user['id']);
        }
        if ($this->request->isAjax()) {
            if (input('post.do') == 'stat') {
                $stat = [];
                if ($this->request->user['level'] == 2) {
                    $stat['domains'] = Db::name('domain')->count();
                } else {
                    $stat['domains'] = Db::name('domain')->where('name', 'in', $this->request->user['permission'])->count();
                }
                $stat['tasks'] = Db::name('dmtask')->count();
                $stat['certs'] = Db::name('cert_order')->count();
                $stat['deploys'] = Db::name('cert_deploy')->count();

                $run_time = config_get('run_time', null, true);
                $run_state = $run_time ? (time() - strtotime($run_time) > 10 ? 0 : 1) : 0;
                $stat['dmonitor_state'] = $run_state;
                $stat['dmonitor_active'] = Db::name('dmtask')->where('active', 1)->count();
                $stat['dmonitor_status_0'] = Db::name('dmtask')->where('status', 0)->count();
                $stat['dmonitor_status_1'] = Db::name('dmtask')->where('status', 1)->count();

                $stat['optimizeip_active'] = Db::name('optimizeip')->where('active', 1)->count();
                $stat['optimizeip_status_1'] = Db::name('optimizeip')->where('status', 1)->count();
                $stat['optimizeip_status_2'] = Db::name('optimizeip')->where('status', 2)->count();

                $stat['certorder_status_3'] = Db::name('cert_order')->where('status', 3)->count();
                $stat['certorder_status_5'] = Db::name('cert_order')->where('status', '<', 0)->count();
                $stat['certorder_status_6'] = Db::name('cert_order')->where('expiretime', '<', date('Y-m-d H:i:s', time() + 86400 * 7))->where('expiretime', '>=', date('Y-m-d H:i:s'))->count();
                $stat['certorder_status_7'] = Db::name('cert_order')->where('expiretime', '<', date('Y-m-d H:i:s'))->count();

                $stat['certdeploy_status_0'] = Db::name('cert_deploy')->where('status', 0)->count();
                $stat['certdeploy_status_1'] = Db::name('cert_deploy')->where('status', 1)->count();
                $stat['certdeploy_status_2'] = Db::name('cert_deploy')->where('status', -1)->count();

                return json($stat);
            }
            return json(['code' => -3]);
        }

        if (config('app.dbversion') && config_get('version') != config('app.dbversion')) {
            $this->db_update();
            config_set('version', config('app.dbversion'));
            Cache::clear();
        }

        $tmp = 'version()';
        $mysqlVersion = Db::query("select version()")[0][$tmp];
        $info = [
            'framework_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysqlVersion,
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
            'os' => php_uname(),
            'date' => date("Y-m-d H:i:s"),
        ];
        View::assign('info', $info);
        View::assign('checkupdate', '//auth.cccyun.cc/app/dnsmgr.php?ver=' . config('app.version'));
        return view();
    }

    private function db_update()
    {
        $sqls = file_get_contents(app()->getAppPath() . 'sql/update.sql');
        $mysql_prefix = env('database.prefix', 'dnsmgr_');
        $sqls = explode(';', $sqls);
        foreach ($sqls as $value) {
            $value = trim($value);
            if (empty($value)) continue;
            $value = str_replace('dnsmgr_', $mysql_prefix, $value);
            try {
                Db::execute($value);
            } catch (Exception $e) {
            }
        }
        if(Db::name('account')->count() > 0 && Db::name('account')->whereNotNull('config')->count() == 0) {
            Cache::clear();
            $accounts = Db::name('account')->select();
            foreach ($accounts as $account) {
                if (!empty($account['config']) || !isset(\app\lib\DnsHelper::$dns_config[$account['type']])) continue;
                $config = [];
                $account_fields = ['name', 'sk', 'ext'];
                $i = 0;
                foreach(\app\lib\DnsHelper::$dns_config[$account['type']]['config'] as $field => $item) {
                    if ($field == 'proxy') {
                        $config[$field] = $account['proxy'];
                        break;
                    }
                    if ($i >= 3) break;
                    $account_field = $account_fields[$i++];
                    $config[$field] = isset($account[$account_field]) ? $account[$account_field] : '';
                }
                Db::name('account')->where('id', $account['id'])->update(['config' => json_encode($config)]);
            }
        }
    }

    public function changeskin()
    {
        $skin = input('post.skin');
        if ($this->request->user['level'] == 2) {
            if (cookie('admin_skin')) {
                cookie('admin_skin', null);
            }
            config_set('admin_skin', $skin);
            Cache::delete('configs');
        } else {
            cookie('admin_skin', $skin);
        }
        return json(['code' => 0, 'msg' => 'succ']);
    }

    public function cleancache()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        Cache::clear();
        clearDirectory(app()->getRuntimePath().'cache/');
        clearDirectory(app()->getRuntimePath().'temp/');
        return json(['code' => 0, 'msg' => 'succ']);
    }

    public function doc()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        View::assign('siteurl', $this->request->root(true));
        return view();
    }

    public function setpwd()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        if ($this->request->isPost()) {
            $oldpwd = input('post.oldpwd');
            $newpwd = input('post.newpwd');
            $newpwd2 = input('post.newpwd2');
            if (empty($oldpwd) || empty($newpwd) || empty($newpwd2)) {
                return json(['code' => -1, 'msg' => '密码不能为空']);
            }
            if ($newpwd != $newpwd2) {
                return json(['code' => -1, 'msg' => '两次输入的密码不一致']);
            }
            if (!password_verify($oldpwd, $this->request->user['password'])) {
                return json(['code' => -1, 'msg' => '原密码错误']);
            }
            Db::name('user')->where('id', $this->request->user['id'])->update(['password' => password_hash($newpwd, PASSWORD_DEFAULT)]);
            return json(['code' => 0, 'msg' => 'succ']);
        }
        View::assign('user', $this->request->user);
        return view();
    }

    public function totp()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if ($action == 'generate') {
            try {
                $totp = \app\lib\TOTP::create();
                $totp->setLabel($this->request->user['username']);
                $totp->setIssuer('DNS Manager');
                return json(['code' => 0, 'data' => ['secret' => $totp->getSecret(), 'qrcode' => $totp->getProvisioningUri()]]);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'bind') {
            $secret = input('post.secret');
            $code = input('post.code');
            if (empty($secret)) return json(['code' => -1, 'msg' => '密钥不能为空']);
            if (empty($code)) return json(['code' => -1, 'msg' => '请输入动态口令']);
            try {
                $totp = \app\lib\TOTP::create($secret);
                if (!$totp->verify($code)) {
                    return json(['code' => -1, 'msg' => '动态口令错误']);
                }
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
            Db::name('user')->where('id', $this->request->user['id'])->update(['totp_open' => 1, 'totp_secret' => $secret]);
            return json(['code' => 0, 'msg' => 'succ']);
        } elseif ($action == 'close') {
            Db::name('user')->where('id', $this->request->user['id'])->update(['totp_open' => 0, 'totp_secret' => null]);
            return json(['code' => 0, 'msg' => 'succ']);
        }
    }

    public function test()
    {

    }
}
