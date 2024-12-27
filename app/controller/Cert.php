<?php

namespace app\controller;

use app\BaseController;
use app\lib\CertHelper;
use app\lib\DeployHelper;
use app\service\CertOrderService;
use app\service\CertDeployService;
use Exception;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;

class Cert extends BaseController
{
    public function certaccount()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return view();
    }

    public function deployaccount()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return view();
    }

    public function account_data()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $deploy = input('get.deploy/d', 0);
        $kw = $this->request->post('kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('cert_account')->where('deploy', $deploy);
        if (!empty($kw)) {
            $select->whereLike('name|remark', '%' . $kw . '%');
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            $row['typename'] = $deploy == 1 ? DeployHelper::$deploy_config[$row['type']]['name'] : CertHelper::$cert_config[$row['type']]['name'];
            $row['icon'] = $deploy == 1 ? DeployHelper::$deploy_config[$row['type']]['icon'] : CertHelper::$cert_config[$row['type']]['icon'];
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function account_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        $deploy = input('post.deploy/d', 0);
        $title = $deploy == 1 ? '自动部署账户' : 'SSL证书账户';

        if ($action == 'add') {
            $type = input('post.type');
            $name = input('post.name', null, 'trim');
            $config = input('post.config', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if ($type == 'local') $name = '复制到本机';
            if (empty($name) || empty($config)) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('cert_account')->where('type', $type)->where('config', $config)->find()) {
                return json(['code' => -1, 'msg' => $title.'已存在']);
            }
            Db::startTrans();
            $id = Db::name('cert_account')->insertGetId([
                'type' => $type,
                'name' => $name,
                'config' => $config,
                'remark' => $remark,
                'deploy' => $deploy,
                'addtime' => date('Y-m-d H:i:s'),
            ]);
            try {
                $this->checkAccount($id, $type, $deploy);
                Db::commit();
                return json(['code' => 0, 'msg' => '添加'.$title.'成功！']);
            } catch(Exception $e) {
                Db::rollback();
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_account')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => $title.'不存在']);
            $type = input('post.type');
            $name = input('post.name', null, 'trim');
            $config = input('post.config', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if ($type == 'local') $name = '复制到本机';
            if (empty($name) || empty($config)) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('cert_account')->where('type', $type)->where('config', $config)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => $title.'已存在']);
            }
            Db::startTrans();
            Db::name('cert_account')->where('id', $id)->update([
                'type' => $type,
                'name' => $name,
                'config' => $config,
                'remark' => $remark,
            ]);
            try {
                $this->checkAccount($id, $type, $deploy);
                Db::commit();
                return json(['code' => 0, 'msg' => '修改'.$title.'成功！']);
            } catch(Exception $e) {
                Db::rollback();
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            if($deploy == 0){
                $dcount = DB::name('cert_order')->where('aid', $id)->count();
                if ($dcount > 0) return json(['code' => -1, 'msg' => '该'.$title.'下存在证书订单，无法删除']);
            }else{
                $dcount = DB::name('cert_deploy')->where('aid', $id)->count();
                if ($dcount > 0) return json(['code' => -1, 'msg' => '该'.$title.'下存在自动部署任务，无法删除']);
            }
            Db::name('cert_account')->where('id', $id)->delete();
            return json(['code' => 0]);
        }
        return json(['code' => -3]);
    }

    public function account_form()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');
        $deploy = input('get.deploy/d', 0);
        $title = $deploy == 1 ? '自动部署账户' : 'SSL证书账户';

        $account = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $account = Db::name('cert_account')->where('id', $id)->find();
            if (empty($account)) return $this->alert('error', $title.'不存在');
        }

        $typeList = $deploy == 1 ? DeployHelper::getList() : CertHelper::getList();
        $classList = $deploy == 1 ? DeployHelper::$class_config : CertHelper::$class_config;

        View::assign('title', $title);
        View::assign('info', $account);
        View::assign('typeList', $typeList);
        View::assign('classList', $classList);
        View::assign('action', $action);
        View::assign('deploy', $deploy);
        return View::fetch();
    }

    private function checkAccount($id, $type, $deploy)
    {
        if($deploy == 0){
            $mod = CertHelper::getModel($id);
            if($mod){
                try{
                    $ext = $mod->register();
                    if(is_array($ext)){
                        Db::name('cert_account')->where('id', $id)->update(['ext'=>json_encode($ext)]);
                    }
                    return true;
                }catch(Exception $e){
                    throw new Exception('验证SSL证书账户失败，' . $e->getMessage());
                }
            }else{
                throw new Exception('SSL证书申请模块'.$type.'不存在');
            }
        }else{
            $mod = DeployHelper::getModel($id);
            if($mod){
                try{
                    $mod->check();
                    return true;
                }catch(Exception $e){
                    throw new Exception('验证自动部署账户失败，' . $e->getMessage());
                }
            }else{
                throw new Exception('SSL证书申请模块'.$type.'不存在');
            }
        }
    }

    public function certorder()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $types = [];
        foreach(CertHelper::$cert_config as $key=>$value){
            $types[$key] = $value['name'];
        }
        View::assign('types', $types);
        return view();
    }

    public function order_data()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $domain = $this->request->post('domain', null, 'trim');
        $id = input('post.id');
        $type = input('post.type', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('cert_order')->alias('A')->join('cert_account B', 'A.aid = B.id');
        if (!empty($id)) {
            $select->where('A.id', $id);
        }elseif (!empty($domain)) {
            $oids = Db::name('cert_domain')->where('domain', 'like', '%' . $domain . '%')->column('oid');
            $select->whereIn('A.id', $oids);
        }
        if (!empty($type)) {
            $select->where('B.type', $type);
        }
        $total = $select->count();
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark')->order('id', 'desc')->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            $row['typename'] = CertHelper::$cert_config[$row['type']]['name'];
            $row['icon'] = CertHelper::$cert_config[$row['type']]['icon'];
            $row['domains'] = Db::name('cert_domain')->where('oid', $row['id'])->order('sort','ASC')->column('domain');
            $row['end_day'] = $row['expiretime'] ? ceil((strtotime($row['expiretime']) - time()) / 86400) : null;
            if($row['error']) $row['error'] = htmlspecialchars(str_replace("'", "\\'", $row['error']));
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function order_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');

        if ($action == 'get') {
            $id = input('post.id/d');
            $row = Db::name('cert_order')->where('id', $id)->field('fullchain,privatekey')->find();
            if (!$row) return $this->alert('error', '证书订单不存在');
            $pfx = CertHelper::getPfx($row['fullchain'], $row['privatekey']);
            $row['pfx'] = base64_encode($pfx);
            return json(['code' => 0, 'data' => $row]);
        } elseif ($action == 'add') {
            $domains = input('post.domains', [], 'trim');
            $order = [
                'aid' => input('post.aid/d'),
                'keytype' => input('post.keytype'),
                'keysize' => input('post.keysize'),
                'addtime' => date('Y-m-d H:i:s'),
                'issuer' => '',
                'status' => 0,
            ];
            $domains = array_map('trim', $domains);
            $domains = array_filter($domains, function ($v) {
                return !empty($v);
            });
            $domains = array_unique($domains);
            if (empty($domains)) return json(['code' => -1, 'msg' => '绑定域名不能为空']);
            if (empty($order['aid']) || empty($order['keytype']) || empty($order['keysize'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);

            $res = $this->check_order($order, $domains);
            if (is_array($res)) return json($res);

            Db::startTrans();
            $id = Db::name('cert_order')->insertGetId($order);
            $domainList = [];
            $i=1;
            foreach($domains as $domain){
                $domainList[] = [
                    'oid' => $id,
                    'domain' => $domain,
                    'sort' => $i++,
                ];
            }
            Db::name('cert_domain')->insertAll($domainList);
            Db::commit();
            return json(['code' => 0, 'msg' => '添加证书订单成功！']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_order')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '证书订单不存在']);
            
            $domains = input('post.domains', [], 'trim');
            $order = [
                'aid' => input('post.aid/d'),
                'keytype' => input('post.keytype'),
                'keysize' => input('post.keysize'),
                'updatetime' => date('Y-m-d H:i:s'),
            ];
            $domains = array_map('trim', $domains);
            $domains = array_filter($domains, function ($v) {
                return !empty($v);
            });
            $domains = array_unique($domains);
            if (empty($domains)) return json(['code' => -1, 'msg' => '绑定域名不能为空']);
            if (empty($order['aid']) || empty($order['keytype']) || empty($order['keysize'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);

            $res = $this->check_order($order, $domains);
            if (is_array($res)) return json($res);

            Db::startTrans();
            Db::name('cert_order')->where('id', $id)->update($order);
            Db::name('cert_domain')->where('oid', $id)->delete();
            $domainList = [];
            $i=1;
            foreach($domains as $domain){
                $domainList[] = [
                    'oid' => $id,
                    'domain' => $domain,
                    'sort' => $i++,
                ];
            }
            Db::name('cert_domain')->insertAll($domainList);
            Db::commit();
            return json(['code' => 0, 'msg' => '修改证书订单成功！']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            $dcount = DB::name('cert_deploy')->where('oid', $id)->count();
            if ($dcount > 0) return json(['code' => -1, 'msg' => '该证书关联了自动部署任务，无法删除']);
            try{
                (new CertOrderService($id))->cancel();
            }catch(Exception $e){
            }
            Db::name('cert_order')->where('id', $id)->delete();
            Db::name('cert_domain')->where('oid', $id)->delete();
            return json(['code' => 0]);
        } elseif ($action == 'setauto') {
            $id = input('post.id/d');
            $isauto = input('post.isauto/d');
            Db::name('cert_order')->where('id', $id)->update(['isauto' => $isauto]);
            return json(['code' => 0]);
        } elseif ($action == 'reset') {
            $id = input('post.id/d');
            try{
                $service = new CertOrderService($id);
                $service->cancel();
                $service->reset();
                return json(['code' => 0]);
            }catch(Exception $e){
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'revoke') {
            $id = input('post.id/d');
            try{
                $service = new CertOrderService($id);
                $service->revoke();
                return json(['code' => 0]);
            }catch(Exception $e){
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'show_log') {
            $processid = input('post.processid');
            $file = app()->getRuntimePath().'log/'.$processid.'.log';
            if(!file_exists($file)) return json(['code' => -1, 'msg' => '日志文件不存在']);
            return json(['code' => 0, 'data' => file_get_contents($file), 'time'=>filemtime($file)]);
        }
        return json(['code' => -3]);
    }

    private function check_order($order, $domains)
    {
        $account = Db::name('cert_account')->where('id', $order['aid'])->find();
        if (!$account) return ['code' => -1, 'msg' => 'SSL证书账户不存在'];
        $max_domains = CertHelper::$cert_config[$account['type']]['max_domains'];
        $wildcard = CertHelper::$cert_config[$account['type']]['wildcard'];
        $cname = CertHelper::$cert_config[$account['type']]['cname'];
        if (count($domains) > $max_domains) return ['code' => -1, 'msg' => '域名数量不能超过'.$max_domains.'个'];

        foreach($domains as $domain){
            if(!$wildcard && strpos($domain, '*') !== false) return ['code' => -1, 'msg' => '该证书账户类型不支持泛域名'];
            $mainDomain = getMainDomain($domain);
            $drow = Db::name('domain')->where('name', $mainDomain)->find();
            if (!$drow) {
                if (substr($domain, 0, 2) == '*.') $domain = substr($domain, 2);
                if (!$cname || !Db::name('cert_cname')->where('domain', $domain)->where('status', 1)->find()) {
                    return ['code' => -1, 'msg' => '域名'.$domain.'未在本系统添加'];
                }
            }
        }
        return true;
    }

    public function order_process()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if (function_exists("set_time_limit")) {
            @set_time_limit(0);
        }
        if (function_exists("ignore_user_abort")) {
            @ignore_user_abort(true);
        }
        $id = input('post.id/d');
        $reset = input('post.reset/d', 0);
        try{
            $service = new CertOrderService($id);
            if($reset == 1){
                $service->reset();
            }
            $retcode = $service->process(true);
            if($retcode == 3){
                return json(['code' => 0, 'msg' => '证书已签发成功！']);
            }elseif($retcode == 1){
                return json(['code' => 0, 'msg' => '添加DNS记录成功！请等待DNS生效后点击验证']);
            }
        }catch(Exception $e){
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function order_form()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');

        $order = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $order = Db::name('cert_order')->where('id', $id)->fieldRaw('id,aid,keytype,keysize,status')->find();
            if (empty($order)) return $this->alert('error', '证书订单不存在');
            $order['domains'] = Db::name('cert_domain')->where('oid', $order['id'])->order('sort','ASC')->column('domain');
        }

        $accounts = [];
        foreach (Db::name('cert_account')->where('deploy', 0)->select() as $row) {
            $accounts[$row['id']] = ['name'=>$row['id'].'_'.CertHelper::$cert_config[$row['type']]['name'], 'type'=>$row['type']];
            if (!empty($row['remark'])) {
                $accounts[$row['id']]['name'] .= '（' . $row['remark'] . '）';
            }
        }
        View::assign('accounts', $accounts);

        View::assign('info', $order);
        View::assign('action', $action);
        return View::fetch();
    }


    public function deploytask()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $types = [];
        foreach(DeployHelper::$deploy_config as $key=>$value){
            $types[$key] = $value['name'];
        }
        View::assign('types', $types);
        return view();
    }

    public function deploy_data()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $domain = $this->request->post('domain', null, 'trim');
        $oid = input('post.oid');
        $type = input('post.type', null, 'trim');
        $remark = input('post.remark', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('cert_deploy')->alias('A')->join('cert_account B', 'A.aid = B.id')->join('cert_order C', 'A.oid = C.id')->join('cert_account D', 'C.aid = D.id');
        if (!empty($oid)) {
            $select->where('A.oid', $oid);
        } elseif (!empty($domain)) {
            $oids = Db::name('cert_domain')->where('domain', 'like', '%' . $domain . '%')->column('oid');
            $select->whereIn('oid', $oids);
        }
        if (!empty($type)) {
            $select->where('B.type', $type);
        }
        if (!empty($remark)) {
            $select->where('A.remark', $remark);
        }
        $total = $select->count();
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark,B.name aname,D.type certtype,D.id certaid')->order('id', 'desc')->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            $row['typename'] = DeployHelper::$deploy_config[$row['type']]['name'];
            $row['icon'] = DeployHelper::$deploy_config[$row['type']]['icon'];
            $row['certtypename'] = CertHelper::$cert_config[$row['certtype']]['name'];
            $row['domains'] = Db::name('cert_domain')->where('oid', $row['oid'])->order('sort','ASC')->column('domain');
            if($row['error']) $row['error'] = htmlspecialchars(str_replace("'", "\\'", $row['error']));
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function deploy_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');

        if ($action == 'add') {
            $task = [
                'aid' => input('post.aid/d'),
                'oid' => input('post.oid/d'),
                'config' => input('post.config', null, 'trim'),
                'remark' => input('post.remark', null, 'trim'),
                'addtime' => date('Y-m-d H:i:s'),
                'status' => 0,
                'active' => 1
            ];
            if (empty($task['aid']) || empty($task['oid']) || empty($task['config'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            Db::name('cert_deploy')->insert($task);
            return json(['code' => 0, 'msg' => '添加自动部署任务成功！']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_deploy')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '自动部署任务不存在']);
            
            $task = [
                'aid' => input('post.aid/d'),
                'oid' => input('post.oid/d'),
                'config' => input('post.config', null, 'trim'),
                'remark' => input('post.remark', null, 'trim'),
            ];
            if (empty($task['aid']) || empty($task['oid']) || empty($task['config'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            Db::name('cert_deploy')->where('id', $id)->update($task);
            return json(['code' => 0, 'msg' => '修改自动部署任务成功！']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            Db::name('cert_deploy')->where('id', $id)->delete();
            return json(['code' => 0]);
        } elseif ($action == 'setactive') {
            $id = input('post.id/d');
            $active = input('post.active/d');
            Db::name('cert_deploy')->where('id', $id)->update(['active' => $active]);
            return json(['code' => 0]);
        } elseif ($action == 'reset') {
            $id = input('post.id/d');
            try{
                $service = new CertDeployService($id);
                $service->reset();
                return json(['code' => 0]);
            }catch(Exception $e){
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'show_log') {
            $processid = input('post.processid');
            $file = app()->getRuntimePath().'log/'.$processid.'.log';
            if(!file_exists($file)) return json(['code' => -1, 'msg' => '日志文件不存在']);
            return json(['code' => 0, 'data' => file_get_contents($file), 'time'=>filemtime($file)]);
        }
        return json(['code' => -3]);
    }

    public function deploy_process()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if (function_exists("set_time_limit")) {
            @set_time_limit(0);
        }
        if (function_exists("ignore_user_abort")) {
            @ignore_user_abort(true);
        }
        $id = input('post.id/d');
        $reset = input('post.reset/d', 0);
        try{
            $service = new CertDeployService($id);
            if($reset == 1){
                $service->reset();
            }
            $service->process(true);
            return json(['code' => 0, 'msg' => 'SSL证书部署任务执行成功！']);
        }catch(Exception $e){
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function deploy_form()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');

        $task = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $task = Db::name('cert_deploy')->alias('A')->join('cert_account B', 'A.aid = B.id')->where('A.id', $id)->fieldRaw('A.id,A.aid,A.oid,A.config,A.remark,B.type')->find();
            if (empty($task)) return $this->alert('error', '自动部署任务不存在');
        }

        $accounts = [];
        foreach (Db::name('cert_account')->where('deploy', 1)->select() as $row) {
            $accounts[$row['id']] = ['name'=>$row['id'].'_'.DeployHelper::$deploy_config[$row['type']]['name'], 'type'=>$row['type']];
            if (!empty($row['remark'])) {
                $accounts[$row['id']]['name'] .= '（' . $row['remark'] . '）';
            }
        }
        View::assign('accounts', $accounts);

        $orders = [];
        foreach (Db::name('cert_order')->alias('A')->join('cert_account B', 'A.aid = B.id')->where('status', '<>', 4)->fieldRaw('A.id,A.aid,B.type,B.remark aremark')->order('id', 'desc')->select() as $row) {
            $domains = Db::name('cert_domain')->where('oid', $row['id'])->order('sort','ASC')->column('domain');
            $domainstr = count($domains) > 2 ? implode('、',array_slice($domains, 0, 2)).'等'.count($domains).'个域名' : implode('、',$domains);
            $orders[$row['id']] = ['name'=>$row['id'].'_'.$domainstr.'（'.CertHelper::$cert_config[$row['type']]['name'].'）'];
        }
        View::assign('orders', $orders);

        View::assign('info', $task);
        View::assign('action', $action);
        View::assign('typeList', DeployHelper::getList());
        return View::fetch();
    }

    public function cname()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $domains = [];
        foreach (Db::name('domain')->field('id,name')->select() as $row) {
            $domains[$row['id']] = $row['name'];
        }
        View::assign('domains', $domains);
        return view();
    }

    public function cname_data()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $kw = $this->request->post('kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('cert_cname')->alias('A')->join('domain B', 'A.did = B.id');
        if (!empty($kw)) {
            $select->whereLike('A.domain', '%' . $kw . '%');
        }
        $total = $select->count();
        $rows = $select->order('A.id', 'desc')->limit($offset, $limit)->field('A.*,B.name cnamedomain')->select();

        $list = [];
        foreach ($rows as $row) {
            $row['host'] = $this->getCnameHost($row['domain']);
            $row['record'] = $row['rr'] . '.' . $row['cnamedomain'];
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    private function getCnameHost($domain)
    {
        $main = getMainDomain($domain);
        if ($main == $domain) {
            return '_acme-challenge';
        } else {
            return '_acme-challenge.' . substr($domain, 0, -strlen($main) - 1);
        }
    }

    public function cname_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');

        if ($action == 'add') {
            $data = [
                'domain' => input('post.domain', null, 'trim'),
                'rr' => input('post.rr', null, 'trim'),
                'did' => input('post.did/d'),
                'addtime' => date('Y-m-d H:i:s'),
                'status' => 0
            ];
            if (empty($data['domain']) || empty($data['rr']) || empty($data['did'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (!checkDomain($data['domain'])) return json(['code' => -1, 'msg' => '域名格式不正确']);
            if (Db::name('cert_cname')->where('domain', $data['domain'])->find()) {
                return json(['code' => -1, 'msg' => '域名'.$data['domain'].'已存在']);
            }
            if (Db::name('cert_cname')->where('rr', $data['rr'])->where('did', $data['did'])->find()) {
                return json(['code' => -1, 'msg' => '已存在相同CNAME记录值']);
            }
            Db::name('cert_cname')->insert($data);
            return json(['code' => 0, 'msg' => '添加CMAME代理成功！']);
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_cname')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => 'CMAME代理不存在']);
            
            $data = [
                'rr' => input('post.rr', null, 'trim'),
                'did' => input('post.did/d'),
            ];
            if ($row['rr'] != $data['rr'] || $row['did'] != $data['did']) {
                $data['status'] = 0;
            }
            if (empty($data['rr']) || empty($data['did'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('cert_cname')->where('rr', $data['rr'])->where('did', $data['did'])->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '已存在相同CNAME记录值']);
            }
            Db::name('cert_cname')->where('id', $id)->update($data);
            return json(['code' => 0, 'msg' => '修改CMAME代理成功！']);
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            Db::name('cert_cname')->where('id', $id)->delete();
            return json(['code' => 0]);
        } elseif ($action == 'check') {
            $id = input('post.id/d');
            $row = Db::name('cert_cname')->alias('A')->join('domain B', 'A.did = B.id')->where('A.id', $id)->field('A.*,B.name cnamedomain')->find();
            if (!$row) return json(['code' => -1, 'msg' => '自动部署任务不存在']);

            $status = 1;
            $domain = '_acme-challenge.' . $row['domain'];
            $record = $row['rr'] . '.' . $row['cnamedomain'];
            $result = \app\utils\DnsQueryUtils::get_dns_records($domain, 'CNAME');
            if(!$result || !in_array($record, $result)){
                $result = \app\utils\DnsQueryUtils::query_dns_doh($domain, 'CNAME');
                if(!$result || !in_array($record, $result)){
                    $status = 0;
                }
            }
            if($status != $row['status']){
                Db::name('cert_cname')->where('id', $id)->update(['status' => $status]);
            }
            return json(['code' => 0, 'status' => $status]);
        }
    }

    public function certset()
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
}
