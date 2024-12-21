<?php

namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;
use app\service\OptimizeService;

class Optimizeip extends BaseController
{
    public function opipset()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if ($this->request->isPost()) {
            $params = input('post.');
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

    public function opiplist()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }

    public function opiplist_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $type = input('post.type/d', 1);
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('optimizeip')->alias('A')->join('domain B', 'A.did = B.id');
        if (!empty($kw)) {
            if ($type == 1) {
                $select->whereLike('rr|B.name', '%' . $kw . '%');
            } elseif ($type == 2) {
                $select->whereLike('remark', '%' . $kw . '%');
            }
        }
        $total = $select->count();
        $list = $select->order('A.id', 'desc')->limit($offset, $limit)->field('A.*,B.name domain')->select();

        return json(['total' => $total, 'rows' => $list]);
    }

    public function opipform()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if ($this->request->isPost()) {
            if ($action == 'add') {
                $task = [
                    'did' => input('post.did/d'),
                    'rr' => input('post.rr', null, 'trim'),
                    'type' => input('post.type/d'),
                    'ip_type' => input('post.ip_type', null, 'trim'),
                    'cdn_type' => input('post.cdn_type/d'),
                    'recordnum' => input('post.recordnum/d'),
                    'ttl' => input('post.ttl/d'),
                    'remark' => input('post.remark', null, 'trim'),
                    'addtime' => date('Y-m-d H:i:s'),
                    'active' => 1
                ];

                if (empty($task['did']) || empty($task['rr']) || empty($task['ip_type']) || empty($task['recordnum']) || empty($task['ttl'])) {
                    return json(['code' => -1, 'msg' => '必填项不能为空']);
                }
                if ($task['recordnum'] > 5) {
                    return json(['code' => -1, 'msg' => '解析数量不能超过5个']);
                }
                if (Db::name('optimizeip')->where('did', $task['did'])->where('rr', $task['rr'])->find()) {
                    return json(['code' => -1, 'msg' => '当前域名的优选IP任务已存在']);
                }
                Db::name('optimizeip')->insert($task);
                return json(['code' => 0, 'msg' => '添加成功']);
            } elseif ($action == 'edit') {
                $id = input('post.id/d');
                $task = [
                    'did' => input('post.did/d'),
                    'rr' => input('post.rr', null, 'trim'),
                    'type' => input('post.type/d'),
                    'ip_type' => input('post.ip_type', null, 'trim'),
                    'cdn_type' => input('post.cdn_type/d'),
                    'recordnum' => input('post.recordnum/d'),
                    'ttl' => input('post.ttl/d'),
                    'remark' => input('post.remark', null, 'trim'),
                ];

                if (empty($task['did']) || empty($task['rr']) || empty($task['ip_type']) || empty($task['recordnum']) || empty($task['ttl'])) {
                    return json(['code' => -1, 'msg' => '必填项不能为空']);
                }
                if ($task['recordnum'] > 5) {
                    return json(['code' => -1, 'msg' => '解析数量不能超过5个']);
                }
                if (Db::name('optimizeip')->where('did', $task['did'])->where('rr', $task['rr'])->where('id', '<>', $id)->find()) {
                    return json(['code' => -1, 'msg' => '当前域名的优选IP任务已存在']);
                }
                Db::name('optimizeip')->where('id', $id)->update($task);
                return json(['code' => 0, 'msg' => '修改成功']);
            } elseif ($action == 'setactive') {
                $id = input('post.id/d');
                $active = input('post.active/d');
                Db::name('optimizeip')->where('id', $id)->update(['active' => $active]);
                return json(['code' => 0, 'msg' => '设置成功']);
            } elseif ($action == 'del') {
                $id = input('post.id/d');
                Db::name('optimizeip')->where('id', $id)->delete();
                return json(['code' => 0, 'msg' => '删除成功']);
            } elseif ($action == 'run') {
                $id = input('post.id/d');
                $task = Db::name('optimizeip')->where('id', $id)->find();
                if (empty($task)) return json(['code' => -1, 'msg' => '任务不存在']);
                try {
                    $result = (new OptimizeService())->execute_one($task);
                    Db::name('optimizeip')->where('id', $id)->update(['status' => 1, 'errmsg' => null, 'updatetime' => date('Y-m-d H:i:s')]);
                    return json(['code' => 0, 'msg' => '优选任务执行成功：' . $result]);
                } catch (Exception $e) {
                    Db::name('optimizeip')->where('id', $id)->update(['status' => 2, 'errmsg' => $e->getMessage(), 'updatetime' => date('Y-m-d H:i:s')]);
                    return json(['code' => -1, 'msg' => '优选任务执行失败：' . $e->getMessage(), 'stack' => $e->__toString()]);
                }
            } else {
                return json(['code' => -1, 'msg' => '参数错误']);
            }
        }
        $task = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $task = Db::name('optimizeip')->where('id', $id)->find();
            if (empty($task)) return $this->alert('error', '任务不存在');
        }

        $domains = [];
        foreach (Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->field('A.*')->where('B.type', '<>', 'cloudflare')->select() as $row) {
            $domains[$row['id']] = $row['name'];
        }
        View::assign('domains', $domains);

        View::assign('info', $task);
        View::assign('action', $action);
        return View::fetch();
    }

    public function queryapi()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $optimize_ip_api = input('post.optimize_ip_api/d');
        $optimize_ip_key = input('post.optimize_ip_key', null, 'trim');
        if (empty($optimize_ip_key)) return json(['code' => -1, 'msg' => '参数不能为空']);
        try {
            $result = (new OptimizeService())->get_license($optimize_ip_api, $optimize_ip_key);
            return json(['code' => 0, 'msg' => '当前积分余额：' . $result]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function status()
    {
        $run_time = Db::name('optimizeip')->where('active', 1)->order('updatetime', 'desc')->value('updatetime');
        $run_state = $run_time ? (time() - strtotime($run_time) > 3600 ? 0 : 1) : 0;
        return $run_state == 1 ? 'ok' : 'error';
    }
}
