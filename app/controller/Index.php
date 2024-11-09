<?php

namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;
use app\lib\DnsHelper;

class Index extends BaseController
{
    public function index()
    {
        if ($this->request->user['type'] == 'domain') {
            return redirect('/record/' . $this->request->user['id']);
        }
        if ($this->request->isAjax()) {
            if (input('post.do') == 'stat') {
                $stat = ['domains' => 0, 'users' => 0, 'records' => 0, 'types' => count(DnsHelper::$dns_config)];
                if ($this->request->user['level'] == 2) {
                    $stat['domains'] = Db::name('domain')->count();
                    $stat['users'] = Db::name('user')->count();
                    $stat['records'] = Db::name('domain')->sum('recordcount');
                } else {
                    $stat['domains'] = Db::name('domain')->where('name', 'in', $this->request->user['permission'])->count();
                    $stat['users'] = 1;
                    $stat['records'] = Db::name('domain')->where('name', 'in', $this->request->user['permission'])->sum('recordcount');
                }
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
            'framework_version' => app()::VERSION,
            'php_version' => PHP_VERSION,
            'mysql_version' => $mysqlVersion,
            'software' => $_SERVER['SERVER_SOFTWARE'],
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
        return view();
    }

    public function test()
    {
        //$a = \app\lib\DnsQueryUtils::query_dns_doh('www.cccyun.cc', 'A');
        //print_r($a);
        $dnsList = json_decode('{"cccyun.net":[{"name":"@","type":"CAA","value":"0 issue \"letsencrypt.org\""},{"name":"verify","type":"TXT","value":"TXTTEST1"},{"name":"verify","type":"TXT","value":"TXTTEST2"}],"yuncname.com":[{"name":"@","type":"CAA","value":"0 issue \"letsencrypt.org\""},{"name":"verify.testhost1","type":"CNAME","value":"i.trust.com"},{"name":"verify.testhost2","type":"CNAME","value":"i.trust.com"}]}', true);
        \app\lib\CertDnsUtils::addDns($dnsList, function ($txt) {
            echo $txt . PHP_EOL;
        });
    }
}
