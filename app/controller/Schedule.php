<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;
use app\service\ScheduleService;

class Schedule extends BaseController
{
    public function stask()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }

    public function stask_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $type = input('post.type/d', 1);
        $kw = input('post.kw', null, 'trim');
        $stype = input('post.stype', null);
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('sctask')->alias('A')->join('domain B', 'A.did = B.id');
        if (!empty($kw)) {
            if ($type == 1) {
                $select->whereLike('rr|B.name', '%' . $kw . '%');
            } elseif ($type == 2) {
                $select->where('recordid', $kw);
            } elseif ($type == 3) {
                $select->where('value', $kw);
            } elseif ($type == 4) {
                $select->whereLike('remark', '%' . $kw . '%');
            }
        }
        if (!isNullOrEmpty($stype)) {
            $select->where('type', $stype);
        }
        $total = $select->count();
        $list = $select->order('A.id', 'desc')->limit($offset, $limit)->field('A.*,B.name domain')->select()->toArray();

        foreach ($list as &$row) {
            $row['addtimestr'] = date('Y-m-d H:i:s', $row['addtime']);
            $row['updatetimestr'] = $row['updatetime'] > 0 ? date('Y-m-d H:i:s', $row['updatetime']) : '未运行';
            $row['nexttimestr'] = $row['nexttime'] > 0 ? date('Y-m-d H:i:s', $row['nexttime']) : '无';
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function stask_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if ($action == 'add') {
            $task = [
                'did' => input('post.did/d'),
                'rr' => input('post.rr', null, 'trim'),
                'recordid' => input('post.recordid', null, 'trim'),
                'type' => input('post.type/d'),
                'cycle' => input('post.cycle/d'),
                'switchtype' => input('post.switchtype/d'),
                'switchdate' => input('post.switchdate', null, 'trim'),
                'switchtime' => input('post.switchtime', null, 'trim'),
                'value' => input('post.value', null, 'trim'),
                'line' => input('post.line', null, 'trim'),
                'remark' => input('post.remark', null, 'trim'),
                'recordinfo' => input('post.recordinfo', null, 'trim'),
                'addtime' => time(),
                'active' => 1
            ];

            if (empty($task['did']) || empty($task['rr']) || empty($task['recordid'])) {
                return json(['code' => -1, 'msg' => '必填项不能为空']);
            }
            if (Db::name('sctask')->where('recordid', $task['recordid'])->where('switchtype', $task['switchtype'])->where('switchtime', $task['switchtime'])->find()) {
                return json(['code' => -1, 'msg' => '当前定时切换策略已存在']);
            }
            $id = Db::name('sctask')->insertGetId($task);
            $row = Db::name('sctask')->where('id', $id)->find();
            (new ScheduleService())->update_nexttime($row);
            return json(['code' => 0, 'msg' => '添加成功']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $task = [
                'did' => input('post.did/d'),
                'rr' => input('post.rr', null, 'trim'),
                'recordid' => input('post.recordid', null, 'trim'),
                'type' => input('post.type/d'),
                'cycle' => input('post.cycle/d'),
                'switchtype' => input('post.switchtype/d'),
                'switchdate' => input('post.switchdate', null, 'trim'),
                'switchtime' => input('post.switchtime', null, 'trim'),
                'value' => input('post.value', null, 'trim'),
                'line' => input('post.line', null, 'trim'),
                'remark' => input('post.remark', null, 'trim'),
                'recordinfo' => input('post.recordinfo', null, 'trim'),
            ];

            if (empty($task['did']) || empty($task['rr']) || empty($task['recordid'])) {
                return json(['code' => -1, 'msg' => '必填项不能为空']);
            }
            if (Db::name('sctask')->where('recordid', $task['recordid'])->where('switchtype', $task['switchtype'])->where('switchtime', $task['switchtime'])->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '当前定时切换策略已存在']);
            }
            Db::name('sctask')->where('id', $id)->update($task);
            $row = Db::name('sctask')->where('id', $id)->find();
            (new ScheduleService())->update_nexttime($row);
            return json(['code' => 0, 'msg' => '修改成功']);
        } elseif ($action == 'setactive') {
            $id = input('post.id/d');
            $active = input('post.active/d');
            Db::name('sctask')->where('id', $id)->update(['active' => $active]);
            return json(['code' => 0, 'msg' => '设置成功']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            Db::name('sctask')->where('id', $id)->delete();
            return json(['code' => 0, 'msg' => '删除成功']);
        } elseif ($action == 'operation') {
            $ids = input('post.ids');
            $success = 0;
            foreach ($ids as $id) {
                if (input('post.act') == 'delete') {
                    Db::name('sctask')->where('id', $id)->delete();
                    $success++;
                } elseif (input('post.act') == 'open' || input('post.act') == 'close') {
                    $isauto = input('post.act') == 'open' ? 1 : 0;
                    Db::name('sctask')->where('id', $id)->update(['active' => $isauto]);
                    $success++;
                }
            }
            return json(['code' => 0, 'msg' => '成功操作' . $success . '个定时切换策略']);
        } else {
            return json(['code' => -1, 'msg' => '参数错误']);
        }
    }

    public function staskform()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        $task = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $task = Db::name('sctask')->where('id', $id)->find();
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
        return View::fetch();
    }

}
