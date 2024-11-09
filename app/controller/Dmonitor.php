<?php

namespace app\controller;

use app\BaseController;
use app\lib\MsgNotice;
use app\model\Dmlog;
use app\model\Dmtask;
use Exception;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\facade\Db;
use think\facade\Request;
use think\facade\View;
use think\facade\Cache;
use app\model\Domain;

class Dmonitor extends BaseController
{
    public function overview()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        $switch_count = Dmlog::where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->count();
        $fail_count = Dmlog::where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->where('action', 1)->count();

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
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        return View::fetch();
    }

    public function task_data()
    {
        if (!checkPermission(2)) {
            return json(['total' => 0, 'rows' => []]);
        }
        $type = $this->request->post('type', 1);
        $kw = $this->request->post('kw', null, 'trim');
        $offset = $this->request->post('offset');
        $limit = $this->request->post('limit');

        $select = Dmtask::alias('A')->join('domain B', 'A.did = B.id');
        if (!empty($kw)) {
            $select = match ($type) {
                1 => $select->where('rr', $kw),
                2 => $select->where('recordid', $kw),
                3 => $select->where('main_value', $kw),
                4 => $select->where('backup_value', $kw),
                5 => $select->where('remark', $kw),
                default => throw new Exception('参数错误'),
            };
        }
        $total = $select->count();
        $list = $select->order('A.id', 'desc')->limit($offset, $limit)->field('A.*,B.name domain')->select()->toArray();

        foreach ($list as &$row) {
            $row['checktimestr'] = date('Y-m-d H:i:s', $row['checktime']);
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function taskform()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        $action = $this->request->param('action');
        if ($this->request->isPost()) {
            if ($action == 'add') {
                $task = $this->request->post();
                $task['addtime'] = time();
                $task['active'] = 1;

                if (!isset($task['did'], $task['rr'], $task['recordid'], $task['main_value'], $task['frequency'], $task['cycle'])) {
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
                $id = $this->request->post('id');
                $task = $this->request->post();
                unset($task['id']);

                if (!isset($task['did'], $task['rr'], $task['recordid'], $task['main_value'], $task['frequency'], $task['cycle'])) {
                    return json(['code' => -1, 'msg' => '必填项不能为空']);
                }
                if ($task['checktype'] > 0 && $task['timeout'] > $task['frequency']) {
                    return json(['code' => -1, 'msg' => '为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
                }
                if ($task['type'] == 2 && $task['backup_value'] == $task['main_value']) {
                    return json(['code' => -1, 'msg' => '主备地址不能相同']);
                }
                if (Dmtask::where('recordid', $task['recordid'])->where('id', '<>', $id)->find()) {
                    return json(['code' => -1, 'msg' => '当前容灾切换策略已存在']);
                }
                Dmtask::where('id', $id)->update($task);
                return json(['code' => 0, 'msg' => '修改成功']);
            } elseif ($action == 'setactive') {
                $id = $this->request->post('id');
                $active = $this->request->post('active');
                Dmtask::where('id', $id)->update(['active' => $active]);
                return json(['code' => 0, 'msg' => '设置成功']);
            } elseif ($action == 'del') {
                $id = $this->request->post('id');
                Dmtask::where('id', $id)->delete();
                Dmlog::where('taskid', $id)->delete();
                return json(['code' => 0, 'msg' => '删除成功']);
            } else {
                return json(['code' => -1, 'msg' => '参数错误']);
            }
        }
        $task = null;
        if ($action == 'edit') {
            $id = $this->request->get('id');
            $task = Dmtask::where('id', $id)->find();
            if (empty($task)) {
                return $this->alert('error', '切换策略不存在');
            }
        }

        $domains = [];
        foreach (Domain::select() as $row) {
            $domains[$row['id']] = $row['name'];
        }
        View::assign('domains', $domains);

        View::assign('info', $task);
        View::assign('action', $action);
        View::assign('support_ping', function_exists('exec') ? '1' : '0');
        return View::fetch();
    }

    public function taskinfo()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        $id = $this->request->param('id');
        $task = Dmtask::where('id', $id)->find();
        if (empty($task)) {
            return $this->alert('error', '切换策略不存在');
        }

        $switch_count = Dmlog::where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->count();
        $fail_count = Dmlog::where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s", strtotime("-1 days")))->where('action', 1)->count();

        $task['switch_count'] = $switch_count;
        $task['fail_count'] = $fail_count;
        if ($task['type'] == 3) {
            $task['action_name'] = ['未知', '<span style="color: red; ">开启解析</span>', '<span style="color: green; ">暂停解析</span>'];
        } elseif ($task['type'] == 2) {
            $task['action_name'] = ['未知', '<span style="color: red; ">切换备用解析记录</span>', '<span style="color: green; ">恢复主解析记录</span>'];
        } else {
            $task['action_name'] = ['未知', '<span style="color: red; ">暂停解析</span>', '<span style="color: green; ">启用解析</span>'];
        }
        View::assign('info', $task);
        return View::fetch();
    }

    public function tasklog_data()
    {
        if (!checkPermission(2)) {
            return json(['total' => 0, 'rows' => []]);
        }
        $taskid = $this->request->param('id');
        $offset = $this->request->post('offset');
        $limit = $this->request->post('limit');
        $action = $this->request->post('action', 0);

        $select = Dmlog::where('taskid', $taskid);
        if ($action > 0) {
            $select->where('action', $action);
        }
        $total = $select->count();
        $list = $select->order('id', 'desc')->limit($offset, $limit)->select();

        return json(['total' => $total, 'rows' => $list]);
    }

    public function noticeset()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        if ($this->request->isPost()) {
            $params = $this->request->post();
            if (isset($params['mail_type']) && isset($params['mail_name2']) && $params['mail_type'] > 0) {
                $params['mail_name'] = $params['mail_name2'];
                unset($params['mail_name2']);
            }
            foreach ($params as $key => $value) {
                if (empty($key)) {
                    continue;
                }
                config_set($key, $value);
                Cache::delete('configs');
            }
            return json(['code' => 0, 'msg' => 'succ']);
        }
        return View::fetch();
    }

    public function proxyset()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        if ($this->request->isPost()) {
            $params = $this->request->post();
            foreach ($params as $key => $value) {
                if (empty($key)) {
                    continue;
                }
                config_set($key, $value);
                Cache::delete('configs');
            }
            return json(['code' => 0, 'msg' => 'succ']);
        }
        return View::fetch();
    }

    public function mailtest()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        $mail_name = config_get('mail_recv') ? config_get('mail_recv') : config_get('mail_name');
        if (empty($mail_name)) {
            return json(['code' => -1, 'msg' => '您还未设置邮箱！']);
        }
        $result = MsgNotice::send_mail($mail_name, '邮件发送测试。', '这是一封测试邮件！<br/><br/>来自：' . $this->request->root(true));
        if ($result === true) {
            return json(['code' => 0, 'msg' => '邮件发送成功！']);
        } else {
            return json(['code' => -1, 'msg' => '邮件发送失败！' . $result]);
        }
    }

    public function tgbottest()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        $tgbot_token = config_get('tgbot_token');
        $tgbot_chatid = config_get('tgbot_chatid');
        if (empty($tgbot_token) || empty($tgbot_chatid)) {
            return json(['code' => -1, 'msg' => '请先保存设置']);
        }
        $content = "<strong>消息发送测试</strong>\n\n这是一封测试消息！\n\n来自：" . $this->request->root(true);
        $result = MsgNotice::send_telegram_bot($content);
        if ($result === true) {
            return json(['code' => 0, 'msg' => '消息发送成功！']);
        } else {
            return json(['code' => -1, 'msg' => '消息发送失败！' . $result]);
        }
    }

    public function clean()
    {
        if (!checkPermission(2)) {
            return $this->alert('error', '无权限');
        }
        if (Request::isPost()) {
            $days = $this->request->post('days');
            if (!$days || $days < 0) {
                return json(['code' => -1, 'msg' => '参数错误']);
            }
            Dmlog::where('date', '<', date("Y-m-d H:i:s", strtotime("-" . $days . " days")))->delete();
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
