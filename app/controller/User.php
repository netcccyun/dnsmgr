<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Request;

class User extends BaseController
{
    public function user()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        $list = Db::name('domain')->select();
        $domains = [];
        foreach ($list as $row) {
            $domains[] = $row['name'];
        }
        View::assign('domains', $domains);
        return view();
    }

    public function user_data()
    {
        if (!checkPermission(2)) {
            return json(['total' => 0, 'rows' => []]);
        }
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('user');
        if (!empty($kw)) {
            $select->whereLike('id|username', $kw);
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

    public function user_op()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        $act = input('param.act');
        if ($act == 'get') {
            $id = input('post.id/d');
            $row = Db::name('user')->where('id', $id)->find();
            if (!$row) {
                return json(['code' => -1, 'msg' => '用户不存在']);
            }
            $row['permission'] = Db::name('permission')->where('uid', $id)->column('domain');
            return json(['code' => 0, 'data' => $row]);
        } elseif ($act == 'add') {
            $username = input('post.username', null, 'trim');
            $password = input('post.password', null, 'trim');
            $is_api = input('post.is_api/d');
            $apikey = input('post.apikey', null, 'trim');
            $level = input('post.level/d');
            if (empty($username) || empty($password)) {
                return json(['code' => -1, 'msg' => '用户名或密码不能为空']);
            }
            if ($is_api == 1 && empty($apikey)) {
                return json(['code' => -1, 'msg' => 'API密钥不能为空']);
            }
            if (Db::name('user')->where('username', $username)->find()) {
                return json(['code' => -1, 'msg' => '用户名已存在']);
            }
            $uid = Db::name('user')->insertGetId([
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'is_api' => $is_api,
                'apikey' => $apikey,
                'level' => $level,
                'regtime' => date('Y-m-d H:i:s'),
                'status' => 1,
            ]);
            if ($level == 1) {
                $permission = input('post.permission/a');
                if (!empty($permission)) {
                    $data = [];
                    foreach ($permission as $domain) {
                        $data[] = ['uid' => $uid, 'domain' => $domain];
                    }
                    Db::name('permission')->insertAll($data);
                }
            }
            return json(['code' => 0, 'msg' => '添加用户成功！']);
        } elseif ($act == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('user')->where('id', $id)->find();
            if (!$row) {
                return json(['code' => -1, 'msg' => '用户不存在']);
            }
            $username = input('post.username', null, 'trim');
            $is_api = input('post.is_api/d');
            $apikey = input('post.apikey', null, 'trim');
            $level = input('post.level/d');
            $repwd = input('post.repwd', null, 'trim');
            if (empty($username)) {
                return json(['code' => -1, 'msg' => '用户名不能为空']);
            }
            if ($is_api == 1 && empty($apikey)) {
                return json(['code' => -1, 'msg' => 'API密钥不能为空']);
            }
            if (Db::name('user')->where('username', $username)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '用户名已存在']);
            }
            if ($level == 1 && ($id == 1000 || $id == $this->request->user['id'])) {
                $level = 2;
            }
            Db::name('user')->where('id', $id)->update([
                'username' => $username,
                'is_api' => $is_api,
                'apikey' => $apikey,
                'level' => $level,
            ]);
            Db::name('permission')->where(['uid' => $id])->delete();
            if ($level == 1) {
                $permission = input('post.permission/a');
                if (!empty($permission)) {
                    $data = [];
                    foreach ($permission as $domain) {
                        $data[] = ['uid' => $id, 'domain' => $domain];
                    }
                    Db::name('permission')->insertAll($data);
                }
            }
            if (!empty($repwd)) {
                Db::name('user')->where('id', $id)->update(['password' => password_hash($repwd, PASSWORD_DEFAULT)]);
            }
            return json(['code' => 0, 'msg' => '修改用户成功！']);
        } elseif ($act == 'set') {
            $id = input('post.id/d');
            $status = input('post.status/d');
            if ($id == 1000) {
                return json(['code' => -1, 'msg' => '此用户无法修改状态']);
            }
            if ($id == $this->request->user['id']) {
                return json(['code' => -1, 'msg' => '当前登录用户无法修改状态']);
            }
            Db::name('user')->where('id', $id)->update(['status' => $status]);
            return json(['code' => 0]);
        } elseif ($act == 'del') {
            $id = input('post.id/d');
            if ($id == 1000) {
                return json(['code' => -1, 'msg' => '此用户无法删除']);
            }
            if ($id == $this->request->user['id']) {
                return json(['code' => -1, 'msg' => '当前登录用户无法删除']);
            }
            Db::name('user')->where('id', $id)->delete();
            return json(['code' => 0]);
        }
        return json(['code' => -3]);
    }

    public function log()
    {
        return view();
    }

    public function log_data()
    {
        $uid = input('post.uid', null, 'trim');
        $kw = input('post.kw', null, 'trim');
        $domain = input('post.domain', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('log');
        if ($this->request->user['type'] == 'domain') {
            $select->where('domain', $this->request->user['name']);
        } elseif ($this->request->user['level'] == 1) {
            $select->where('uid', $this->request->user['id']);
        } elseif (!empty($uid)) {
            $select->where('uid', $uid);
        }
        if (!empty($kw)) {
            $select->whereLike('action|data', '%' . $kw . '%');
        }
        if (!empty($domain)) {
            $select->where('domain', $domain);
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $rows]);
    }

}
