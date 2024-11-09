<?php

namespace app\controller;

use app\BaseController;
use app\model\Log;
use app\model\Permission;
use app\model\User as UserModel;
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
        $kw = $this->request->post('kw', null, 'trim');
        $offset = $this->request->post('offset');
        $limit = $this->request->post('limit');

        $select = new UserModel();
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
        $act = $this->request->param('act');
        if ($act == 'get') {
            $id = $this->request->post('id');
            $row = UserModel::where('id', $id)->find();
            if (!$row) {
                return json(['code' => -1, 'msg' => '用户不存在']);
            }
            $row['permission'] = Permission::where('uid', $id)->column('domain');
            return json(['code' => 0, 'data' => $row]);
        } elseif ($act == 'add') {
            $username = $this->request->post('username', null, 'trim');
            $password = $this->request->post('password', null, 'trim');
            $is_api = $this->request->post('is_api');
            $apikey = $this->request->post('apikey', null, 'trim');
            $level = $this->request->post('level');
            if (empty($username) || empty($password)) {
                return json(['code' => -1, 'msg' => '用户名或密码不能为空']);
            }
            if ($is_api == 1 && empty($apikey)) {
                return json(['code' => -1, 'msg' => 'API密钥不能为空']);
            }
            if (UserModel::where('username', $username)->find()) {
                return json(['code' => -1, 'msg' => '用户名已存在']);
            }
            $uid = UserModel::insertGetId([
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'is_api' => $is_api,
                'apikey' => $apikey,
                'level' => $level,
                'regtime' => date('Y-m-d H:i:s'),
                'status' => 1,
            ]);
            if ($level == 1) {
                $permission = $this->request->post('permission');
                if (!empty($permission)) {
                    $data = [];
                    foreach ($permission as $domain) {
                        $data[] = ['uid' => $uid, 'domain' => $domain];
                    }
                    Permission::insertAll($data);
                }
            }
            return json(['code' => 0, 'msg' => '添加用户成功！']);
        } elseif ($act == 'edit') {
            $id = $this->request->post('id');
            $row = UserModel::where('id', $id)->find();
            if (!$row) {
                return json(['code' => -1, 'msg' => '用户不存在']);
            }
            $username = $this->request->post('username', null, 'trim');
            $is_api = $this->request->post('is_api');
            $apikey = $this->request->post('apikey', null, 'trim');
            $level = $this->request->post('level');
            $repwd = $this->request->post('repwd', null, 'trim');
            if (empty($username)) {
                return json(['code' => -1, 'msg' => '用户名不能为空']);
            }
            if ($is_api == 1 && empty($apikey)) {
                return json(['code' => -1, 'msg' => 'API密钥不能为空']);
            }
            if (UserModel::where('username', $username)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '用户名已存在']);
            }
            if ($level == 1 && ($id == 1000 || $id == $this->request->user['id'])) {
                $level = 2;
            }
            UserModel::where('id', $id)->update([
                'username' => $username,
                'is_api' => $is_api,
                'apikey' => $apikey,
                'level' => $level,
            ]);
            Permission::where(['uid' => $id])->delete();
            if ($level == 1) {
                $permission = $this->request->post('permission');
                if (!empty($permission)) {
                    $data = [];
                    foreach ($permission as $domain) {
                        $data[] = ['uid' => $id, 'domain' => $domain];
                    }
                    Permission::insertAll($data);
                }
            }
            if (!empty($repwd)) {
                UserModel::where('id', $id)->update(['password' => password_hash($repwd, PASSWORD_DEFAULT)]);
            }
            return json(['code' => 0, 'msg' => '修改用户成功！']);
        } elseif ($act == 'set') {
            $id = $this->request->post('id');
            $status = $this->request->post('status');
            if ($id == 1000) {
                return json(['code' => -1, 'msg' => '此用户无法修改状态']);
            }
            if ($id == $this->request->user['id']) {
                return json(['code' => -1, 'msg' => '当前登录用户无法修改状态']);
            }
            UserModel::where('id', $id)->update(['status' => $status]);
            return json(['code' => 0]);
        } elseif ($act == 'del') {
            $id = $this->request->post('id');
            if ($id == 1000) {
                return json(['code' => -1, 'msg' => '此用户无法删除']);
            }
            if ($id == $this->request->user['id']) {
                return json(['code' => -1, 'msg' => '当前登录用户无法删除']);
            }
            UserModel::where('id', $id)->delete();
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
        $uid = $this->request->post('uid', null, 'trim');
        $kw = $this->request->post('kw', null, 'trim');
        $domain = $this->request->post('domain', null, 'trim');
        $offset = $this->request->post('offset');
        $limit = $this->request->post('limit');

        $select = new Log();
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
