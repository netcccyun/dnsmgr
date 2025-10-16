<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;

class Dmonitor extends BaseController
{
    public function overview()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $switch_count = Db::name('dmlog')->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->count();
        $fail_count = Db::name('dmlog')->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->where('action', 1)->count();

        $run_time = config_get('run_time', null, true);
        $run_state = $run_time ? (time() - strtotime($run_time) > 10 ? 0 : 1) : 0;
        View::assign('info', [
            'run_count' => config_get('run_count', null, true) ?? 0,
            'run_time' => $run_time ?? '无',
            'run_state' => $run_state,
            'run_error' => config_get('run_error', null, true),
            'switch_count' => $switch_count,
            'fail_count' => $fail_count,
            'swoole' => extension_loaded('swoole') ? '<font color="green">已安装</font>' : '<font color="red">未安装</font>',
        ]);
        return View::fetch();
    }

    public function task()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }

    public function task_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $type = input('post.type/d', 1);
        $status = input('post.status', null);
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('dmtask')->alias('A')->join('domain B', 'A.did = B.id');
        if (!empty($kw)) {
            if ($type == 1) {
                $select->whereLike('rr|B.name', '%' . $kw . '%');
            } elseif ($type == 2) {
                $select->where('recordid', $kw);
            } elseif ($type == 3) {
                $select->where('main_value', $kw);
            } elseif ($type == 4) {
                $select->where('backup_value', $kw);
            } elseif ($type == 5) {
                $select->whereLike('remark', '%' . $kw . '%');
            }
        }
        if (!isNullOrEmpty($status)) {
            $select->where('status', intval($status));
        }
        $total = $select->count();
        $list = $select->order('A.id', 'desc')->limit($offset, $limit)->field('A.*,B.name domain')->select()->toArray();

        foreach ($list as &$row) {
            $row['addtimestr'] = date('Y-m-d H:i:s', $row['addtime']);
            $row['checktimestr'] = $row['checktime'] > 0 ? date('Y-m-d H:i:s', $row['checktime']) : '未运行';
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function task_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if ($action == 'add') {
            $task = [
                'did' => input('post.did/d'),
                'rr' => input('post.rr', null, 'trim'),
                'recordid' => input('post.recordid', null, 'trim'),
                'type' => input('post.type/d'),
                'main_value' => input('post.main_value', null, 'trim'),
                'backup_value' => input('post.backup_value', null, 'trim'),
                'checktype' => input('post.checktype/d'),
                'checkurl' => input('post.checkurl', null, 'trim'),
                'tcpport' => !empty(input('post.tcpport')) ? input('post.tcpport/d') : null,
                'frequency' => input('post.frequency/d'),
                'cycle' => input('post.cycle/d'),
                'timeout' => input('post.timeout/d'),
                'proxy' => input('post.proxy/d'),
                'cdn' => input('post.cdn') == 'true' || input('post.cdn') == '1' ? 1 : 0,
                'remark' => input('post.remark', null, 'trim'),
                'recordinfo' => input('post.recordinfo', null, 'trim'),
                'addtime' => time(),
                'active' => 1
            ];

            if (empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['main_value']) || empty($task['frequency']) || empty($task['cycle'])) {
                return json(['code' => -1, 'msg' => '必填项不能为空']);
            }
            if ($task['checktype'] > 0 && $task['timeout'] > $task['frequency']) {
                return json(['code' => -1, 'msg' => '为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
            }
            if ($task['type'] == 2 && $task['backup_value'] == $task['main_value']) {
                return json(['code' => -1, 'msg' => '主备地址不能相同']);
            }
            if (Db::name('dmtask')->where('recordid', $task['recordid'])->find()) {
                return json(['code' => -1, 'msg' => '当前容灾切换策略已存在']);
            }
            Db::name('dmtask')->insert($task);
            return json(['code' => 0, 'msg' => '添加成功']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $task = [
                'did' => input('post.did/d'),
                'rr' => input('post.rr', null, 'trim'),
                'recordid' => input('post.recordid', null, 'trim'),
                'type' => input('post.type/d'),
                'main_value' => input('post.main_value', null, 'trim'),
                'backup_value' => input('post.backup_value', null, 'trim'),
                'checktype' => input('post.checktype/d'),
                'checkurl' => input('post.checkurl', null, 'trim'),
                'tcpport' => !empty(input('post.tcpport')) ? input('post.tcpport/d') : null,
                'frequency' => input('post.frequency/d'),
                'cycle' => input('post.cycle/d'),
                'timeout' => input('post.timeout/d'),
                'proxy' => input('post.proxy/d'),
                'cdn' => input('post.cdn') == 'true' || input('post.cdn') == '1' ? 1 : 0,
                'remark' => input('post.remark', null, 'trim'),
                'recordinfo' => input('post.recordinfo', null, 'trim'),
            ];

            if (empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['main_value']) || empty($task['frequency']) || empty($task['cycle'])) {
                return json(['code' => -1, 'msg' => '必填项不能为空']);
            }
            if ($task['checktype'] > 0 && $task['timeout'] > $task['frequency']) {
                return json(['code' => -1, 'msg' => '为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
            }
            if ($task['type'] == 2 && $task['backup_value'] == $task['main_value']) {
                return json(['code' => -1, 'msg' => '主备地址不能相同']);
            }
            if (Db::name('dmtask')->where('recordid', $task['recordid'])->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '当前容灾切换策略已存在']);
            }
            Db::name('dmtask')->where('id', $id)->update($task);
            return json(['code' => 0, 'msg' => '修改成功']);
        } elseif ($action == 'setactive') {
            $id = input('post.id/d');
            $active = input('post.active/d');
            Db::name('dmtask')->where('id', $id)->update(['active' => $active]);
            return json(['code' => 0, 'msg' => '设置成功']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            Db::name('dmtask')->where('id', $id)->delete();
            Db::name('dmlog')->where('taskid', $id)->delete();
            return json(['code' => 0, 'msg' => '删除成功']);
        } elseif ($action == 'operation') {
            $ids = input('post.ids');
            $success = 0;
            foreach ($ids as $id) {
                if (input('post.act') == 'delete') {
                    Db::name('dmtask')->where('id', $id)->delete();
                    Db::name('dmlog')->where('taskid', $id)->delete();
                    $success++;
                } elseif (input('post.act') == 'retry') {
                    Db::name('dmtask')->where('id', $id)->update(['checknexttime' => time()]);
                    $success++;
                } elseif (input('post.act') == 'open' || input('post.act') == 'close') {
                    $isauto = input('post.act') == 'open' ? 1 : 0;
                    Db::name('dmtask')->where('id', $id)->update(['active' => $isauto]);
                    $success++;
                }
            }
            return json(['code' => 0, 'msg' => '成功操作' . $success . '个容灾切换策略']);
        } else {
            return json(['code' => -1, 'msg' => '参数错误']);
        }
    }

    public function taskform()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        $task = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $task = Db::name('dmtask')->where('id', $id)->find();
            if (empty($task)) return $this->alert('error', '切换策略不存在');
        }

        $domains = [];
        $domainList = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->field('A.id,A.name,B.type')->select();
        foreach ($domainList as $row) {
            $domains[] = ['id'=>$row['id'], 'name'=>$row['name'], 'type'=>$row['type']];
        }
        View::assign('domains', $domains);

        View::assign('info', $task);
        View::assign('action', $action);
        View::assign('support_ping', function_exists('exec') ? '1' : '0');
        return View::fetch();
    }

    public function taskinfo()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $id = input('param.id/d');
        $task = Db::name('dmtask')->where('id', $id)->find();
        if (empty($task)) return $this->alert('error', '切换策略不存在');

        $switch_count = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->count();
        $fail_count = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->where('action', 1)->count();

        $task['switch_count'] = $switch_count;
        $task['fail_count'] = $fail_count;
        if ($task['type'] == 3) {
            $task['action_name'] = ['未知', '<font color="red">开启解析</font>', '<font color="green">暂停解析</font>'];
        } elseif ($task['type'] == 2) {
            $task['action_name'] = ['未知', '<font color="red">切换备用解析记录</font>', '<font color="green">恢复主解析记录</font>'];
        } else {
            $task['action_name'] = ['未知', '<font color="red">暂停解析</font>', '<font color="green">启用解析</font>'];
        }
        View::assign('info', $task);
        return View::fetch();
    }

    public function tasklog_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $taskid = input('param.id/d');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        $action = input('post.action/d', 0);

        $select = Db::name('dmlog')->where('taskid', $taskid);
        if ($action > 0) {
            $select->where('action', $action);
        }
        $total = $select->count();
        $list = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $list]);
    }

    public function clean()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if ($this->request->isPost()) {
            $days = input('post.days/d');
            if (!$days || $days < 0) return json(['code' => -1, 'msg' => '参数错误']);
            Db::execute("DELETE FROM `" . config('database.connections.mysql.prefix') . "dmlog` WHERE `date`<'" . date("Y-m-d H:i:s", strtotime("-" . $days . " days")) . "'");
            Db::execute("OPTIMIZE TABLE `" . config('database.connections.mysql.prefix') . "dmlog`");
            return json(['code' => 0, 'msg' => '清理成功']);
        }
    }

    public function status()
    {
        $run_time = config_get('run_time', null, true);
        $run_state = $run_time ? (time() - strtotime($run_time) > 10 ? 0 : 1) : 0;
        return $run_state == 1 ? 'ok' : 'error';
    }
}
