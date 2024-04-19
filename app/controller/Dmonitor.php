<?php
namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Db;
use think\facade\View;
use app\lib\DnsHelper;

class Dmonitor extends BaseController
{
    public function overview()
    {
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        $switch_count = Db::name('dmlog')->where('date', '>=', date("Y-m-d H:i:s",strtotime("-1 days")))->count();
        $fail_count = Db::name('dmlog')->where('date', '>=', date("Y-m-d H:i:s",strtotime("-1 days")))->where('action', 1)->count();

        $run_state = config_get('run_time', null, true) ? (time()-strtotime(config_get('run_time')) > 10 ? 0 : 1) : 0;
        View::assign('info', [
            'run_count' => config_get('run_count', null, true) ?? 0,
            'run_time' => config_get('run_time', null, true) ?? '无',
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
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }

    public function task_data(){
        if(!checkPermission(2)) return json(['total'=>0, 'rows'=>[]]);
        $type = input('post.type/d', 1);
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('dmtask')->alias('A')->join('domain B','A.did = B.id');
        if(!empty($kw)){
            if($type == 1){
                $select->whereLike('rr|B.name', '%'.$kw.'%');
            }elseif($type == 2){
                $select->where('recordid', $kw);
            }elseif($type == 3){
                $select->where('main_value', $kw);
            }elseif($type == 4){
                $select->where('backup_value', $kw);
            }elseif($type == 5){
                $select->whereLike('remark', '%'.$kw.'%');
            }
        }
        $total = $select->count();
        $list = $select->order('A.id','desc')->limit($offset, $limit)->field('A.*,B.name domain')->select()->toArray();

        foreach($list as &$row){
            $row['checktimestr'] = date('Y-m-d H:i:s', $row['checktime']);
        }

        return json(['total'=>$total, 'rows'=>$list]);
    }

    public function taskform()
    {
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        if(request()->isPost()){
            if($action == 'add'){
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
                    'remark' => input('post.remark', null, 'trim'),
                    'recordinfo' => input('post.recordinfo', null, 'trim'),
                    'addtime' => time(),
                    'active' => 1
                ];
    
                if(empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['main_value']) || empty($task['frequency']) || empty($task['cycle'])){
                    return json(['code'=>-1, 'msg'=>'必填项不能为空']);
                }
                if($task['checktype'] > 0 && $task['timeout'] > $task['frequency']){
                    return json(['code'=>-1, 'msg'=>'为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
                }
                if($task['type'] == 2 && $task['backup_value'] == $task['main_value']){
                    return json(['code'=>-1, 'msg'=>'主备地址不能相同']);
                }
                if(Db::name('dmtask')->where('recordid', $task['recordid'])->find()){
                    return json(['code'=>-1, 'msg'=>'当前容灾切换策略已存在']);
                }
                Db::name('dmtask')->insert($task);
                return json(['code'=>0, 'msg'=>'添加成功']);
            }elseif($action == 'edit'){
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
                    'remark' => input('post.remark', null, 'trim'),
                    'recordinfo' => input('post.recordinfo', null, 'trim'),
                ];
    
                if(empty($task['did']) || empty($task['rr']) || empty($task['recordid']) || empty($task['main_value']) || empty($task['frequency']) || empty($task['cycle'])){
                    return json(['code'=>-1, 'msg'=>'必填项不能为空']);
                }
                if($task['checktype'] > 0 && $task['timeout'] > $task['frequency']){
                    return json(['code'=>-1, 'msg'=>'为保障容灾切换任务正常运行，最大超时时间不能大于检测间隔']);
                }
                if($task['type'] == 2 && $task['backup_value'] == $task['main_value']){
                    return json(['code'=>-1, 'msg'=>'主备地址不能相同']);
                }
                if(Db::name('dmtask')->where('recordid', $task['recordid'])->where('id', '<>', $id)->find()){
                    return json(['code'=>-1, 'msg'=>'当前容灾切换策略已存在']);
                }
                Db::name('dmtask')->where('id', $id)->update($task);
                return json(['code'=>0, 'msg'=>'修改成功']);
            }elseif($action == 'setactive'){
                $id = input('post.id/d');
                $active = input('post.active/d');
                Db::name('dmtask')->where('id', $id)->update(['active'=>$active]);
                return json(['code'=>0, 'msg'=>'设置成功']);
            }elseif($action == 'del'){
                $id = input('post.id/d');
                Db::name('dmtask')->where('id', $id)->delete();
                Db::name('dmlog')->where('taskid', $id)->delete();
                return json(['code'=>0, 'msg'=>'删除成功']);
            }else{
                return json(['code'=>-1, 'msg'=>'参数错误']);
            }
        }
        $task = null;
        if($action == 'edit'){
            $id = input('get.id/d');
            $task = Db::name('dmtask')->where('id', $id)->find();
            if(empty($task)) return $this->alert('error', '任务不存在');
        }

        $domains = [];
        foreach(Db::name('domain')->select() as $row){
            $domains[$row['id']] = $row['name'];
        }
        View::assign('domains', $domains);

        View::assign('info', $task);
        View::assign('action', $action);
        View::assign('support_ping', function_exists('exec')?'1':'0');
        return View::fetch('taskform');
    }

    public function taskinfo()
    {
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        $id = input('param.id/d');
        $task = Db::name('dmtask')->where('id', $id)->find();
        if(empty($task)) return $this->alert('error', '任务不存在');

        $switch_count = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s",strtotime("-1 days")))->count();
        $fail_count = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', date("Y-m-d H:i:s",strtotime("-1 days")))->where('action', 1)->count();

        $task['switch_count'] = $switch_count;
        $task['fail_count'] = $fail_count;
        if($task['type'] == 2){
            $task['action_name'] = ['未知', '<font color="red">切换备用解析记录</font>', '<font color="green">恢复主解析记录</font>'];
        }else{
            $task['action_name'] = ['未知', '<font color="red">暂停解析</font>', '<font color="green">启用解析</font>'];
        }
        View::assign('info', $task);
        return View::fetch();
    }

    public function tasklog_data(){
        if(!checkPermission(2)) return json(['total'=>0, 'rows'=>[]]);
        $taskid = input('param.id/d');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        $action = input('post.action/d', 0);

        $select = Db::name('dmlog')->where('taskid', $taskid);
        if($action > 0){
            $select->where('action', $action);
        }
        $total = $select->count();
        $list = $select->order('id','desc')->limit($offset, $limit)->select();

        return json(['total'=>$total, 'rows'=>$list]);
    }

    public function noticeset()
    {
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        if(request()->isPost()){
            $params = input('post.');
            if(isset($params['mail_type']) && isset($params['mail_name2']) && $params['mail_type'] > 0){
                $params['mail_name'] = $params['mail_name2'];
                unset($params['mail_name2']);
            }
            foreach ($params as $key=>$value){
                if (empty($key)) {
                    continue;
                }
                config_set($key, $value);
            }
            return json(['code'=>0, 'msg'=>'succ']);
        }
        return View::fetch();
    }

    public function mailtest()
    {
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        $mail_name = config_get('mail_recv')?config_get('mail_recv'):config_get('mail_name');
        if(empty($mail_name)) return json(['code'=>-1, 'msg'=>'您还未设置邮箱！']);
        $result = \app\lib\MsgNotice::send_mail($mail_name,'邮件发送测试。','这是一封测试邮件！<br/><br/>来自：'.request()->root(true));
        if($result === true){
            return json(['code'=>0, 'msg'=>'邮件发送成功！']);
        }else{
            return json(['code'=>-1, 'msg'=>'邮件发送失败！'.$result]);
        }
    }

    public function clean()
    {
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        if(request()->isPost()){
            $days = input('post.days/d');
            if(!$days || $days < 0) return json(['code'=>-1, 'msg'=>'参数错误']);
            Db::execute("DELETE FROM `".config('database.connections.mysql.prefix')."dmlog` WHERE `date`<'".date("Y-m-d H:i:s",strtotime("-".$days." days"))."'");
            Db::execute("OPTIMIZE TABLE `".config('database.connections.mysql.prefix')."dmlog`");
            return json(['code'=>0, 'msg'=>'清理成功']);
        }
    }

    public function status()
    {
        $run_state = config_get('run_time', null, true) ? (time()-strtotime(config_get('run_time')) > 10 ? 0 : 1) : 0;
        return $run_state == 1 ? 'ok' : 'error';
    }
}