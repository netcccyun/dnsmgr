<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Request;
use app\lib\DnsHelper;

class Domain extends BaseController
{
    
    public function account(){
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        View::assign('dnsconfig', DnsHelper::$dns_config);
        return view();
    }

    public function account_data(){
        if(!checkPermission(2)) return json(['total'=>0, 'rows'=>[]]);
        $kw = input('post.kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('account');
        if(!empty($kw)){
            $select->whereLike('ak|remark', '%'.$kw.'%');
        }
        $total = $select->count();
        $rows = $select->order('id','desc')->limit($offset, $limit)->select();

        $list = [];
        foreach($rows as $row){
            $row['typename'] = DnsHelper::$dns_config[$row['type']]['name'];
            $list[] = $row;
        }

        return json(['total'=>$total, 'rows'=>$list]);
    }

    public function account_op(){
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        $act = input('param.act');
        if($act == 'get'){
            $id = input('post.id/d');
            $row = Db::name('account')->where('id', $id)->find();
            if(!$row) return json(['code'=>-1, 'msg'=>'域名账户不存在']);
            return json(['code'=>0, 'data'=>$row]);
        }elseif($act == 'add'){
            $type = input('post.type');
            $ak = input('post.ak', null, 'trim');
            $sk = input('post.sk', null, 'trim');
            $ext = input('post.ext', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if(empty($ak) || empty($sk)) return json(['code'=>-1, 'msg'=>'AccessKey和SecretKey不能为空']);
            if(Db::name('account')->where('type', $type)->where('ak', $ak)->find()){
                return json(['code'=>-1, 'msg'=>'域名账户已存在']);
            }
            Db::startTrans();
            $id = Db::name('account')->insertGetId([
                'type' => $type,
                'ak' => $ak,
                'sk' => $sk,
                'ext' => $ext,
                'remark' => $remark,
                'addtime' => date('Y-m-d H:i:s'),
            ]);
            $dns = DnsHelper::getModel($id);
            if($dns){
                if($dns->check()){
                    Db::commit();
                    return json(['code'=>0, 'msg'=>'添加域名账户成功！']);
                }else{
                    Db::rollback();
                    return json(['code'=>-1, 'msg'=>'验证域名账户失败，'.$dns->getError()]);
                }
            }else{
                Db::rollback();
                return json(['code'=>-1, 'msg'=>'DNS模块('.$type.')不存在']);
            }
           
        }elseif($act == 'edit'){
            $id = input('post.id/d');
            $row = Db::name('account')->where('id', $id)->find();
            if(!$row) return json(['code'=>-1, 'msg'=>'域名账户不存在']);
            $type = input('post.type');
            $ak = input('post.ak', null, 'trim');
            $sk = input('post.sk', null, 'trim');
            $ext = input('post.ext', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if(empty($ak) || empty($sk)) return json(['code'=>-1, 'msg'=>'AccessKey和SecretKey不能为空']);
            if(Db::name('account')->where('type', $type)->where('ak', $ak)->where('id', '<>', $id)->find()){
                return json(['code'=>-1, 'msg'=>'域名账户已存在']);
            }
            Db::startTrans();
            Db::name('account')->where('id', $id)->update([
                'type' => $type,
                'ak' => $ak,
                'sk' => $sk,
                'ext' => $ext,
                'remark' => $remark,
            ]);
            $dns = DnsHelper::getModel($id);
            if($dns){
                if($dns->check()){
                    Db::commit();
                    return json(['code'=>0, 'msg'=>'修改域名账户成功！']);
                }else{
                    Db::rollback();
                    return json(['code'=>-1, 'msg'=>'验证域名账户失败，'.$dns->getError()]);
                }
            }else{
                Db::rollback();
                return json(['code'=>-1, 'msg'=>'DNS模块('.$type.')不存在']);
            }
        }elseif($act == 'del'){
            $id = input('post.id/d');
            $dcount = DB::name('domain')->where('aid', $id)->count();
            if($dcount > 0) return json(['code'=>-1, 'msg'=>'该域名账户下存在域名，无法删除']);
            Db::name('account')->where('id', $id)->delete();
            return json(['code'=>0]);
        }
        return json(['code'=>-3]);
    }


    public function domain(){
        if(request()->user['type'] == 'domain'){
            return redirect('/record/'.request()->user['id']);
        }
        $list = Db::name('account')->select();
        $accounts = [];
        $types = [];
        foreach($list as $row){
            $accounts[$row['id']] = $row['id'].'_'.DnsHelper::$dns_config[$row['type']]['name'];
            if(!array_key_exists($row['type'], $types)){
                $types[$row['type']] = DnsHelper::$dns_config[$row['type']]['name'];
            }
            if(!empty($row['remark'])){
                $accounts[$row['id']] .= '（'.$row['remark'].'）';
            }
        }
        View::assign('accounts', $accounts);
        View::assign('types', $types);
        return view();
    }

    public function domain_data(){
        if(!checkPermission(1)) return json(['total'=>0, 'rows'=>[]]);
        $kw = input('post.kw', null, 'trim');
        $type = input('post.type', null, 'trim');
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);

        $select = Db::name('domain')->alias('A')->join('account B','A.aid = B.id');
        if(!empty($kw)){
            $select->whereLike('name|A.remark', '%'.$kw.'%');
        }
        if(!empty($type)){
            $select->whereLike('B.type', $type);
        }
        if(request()->user['level'] == 1){
            $select->where('is_hide', 0)->where('A.name', 'in', request()->user['permission']);
        }
        $total = $select->count();
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark')->order('A.id','desc')->limit($offset, $limit)->select();

        $list = [];
        foreach($rows as $row){
            $row['typename'] = DnsHelper::$dns_config[$row['type']]['name'];
            $list[] = $row;
        }

        return json(['total'=>$total, 'rows'=>$list]);
    }

    public function domain_op(){
        if(!checkPermission(1)) return $this->alert('error', '无权限');
        $act = input('param.act');
        if($act == 'get'){
            $id = input('post.id/d');
            $row = Db::name('domain')->where('id', $id)->find();
            if(!$row) return json(['code'=>-1, 'msg'=>'域名不存在']);
            return json(['code'=>0, 'data'=>$row]);
        }elseif($act == 'add'){
            if(!checkPermission(2)) return $this->alert('error', '无权限');
            $aid = input('post.aid/d');
            $name = input('post.name', null, 'trim');
            $thirdid = input('post.thirdid', null, 'trim');
            $recordcount = input('post.recordcount/d', 0);
            if(empty($name) || empty($thirdid)) return json(['code'=>-1, 'msg'=>'参数不能为空']);
            if(Db::name('domain')->where('aid', $aid)->where('name', $name)->find()){
                return json(['code'=>-1, 'msg'=>'域名已存在']);
            }
            Db::name('domain')->insert([
                'aid' => $aid,
                'name' => $name,
                'thirdid' => $thirdid,
                'addtime' => date('Y-m-d H:i:s'),
                'is_hide' => 0,
                'is_sso' => 1,
                'recordcount' => $recordcount,
            ]);
            return json(['code'=>0, 'msg'=>'添加域名成功！']);
        }elseif($act == 'edit'){
            if(!checkPermission(2)) return $this->alert('error', '无权限');
            $id = input('post.id/d');
            $row = Db::name('domain')->where('id', $id)->find();
            if(!$row) return json(['code'=>-1, 'msg'=>'域名不存在']);
            $is_hide = input('post.is_hide/d');
            $is_sso = input('post.is_sso/d');
            $remark = input('post.remark', null, 'trim');
            Db::name('domain')->where('id', $id)->update([
                'is_hide' => $is_hide,
                'is_sso' => $is_sso,
                'remark' => $remark,
            ]);
            return json(['code'=>0, 'msg'=>'修改域名配置成功！']);
        }elseif($act == 'del'){
            if(!checkPermission(2)) return $this->alert('error', '无权限');
            $id = input('post.id/d');
            Db::name('domain')->where('id', $id)->delete();
            Db::name('dmtask')->where('did', $id)->delete();
            Db::name('optimizeip')->where('did', $id)->delete();
            return json(['code'=>0]);
        }
        return json(['code'=>-3]);
    }

    public function domain_list(){
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        $aid = input('post.aid/d');
        $kw = input('post.kw', null, 'trim');
        $page = input('?post.page') ? input('post.page/d') : 1;
        $pagesize = input('?post.pagesize') ? input('post.pagesize/d') : 10;
        $dns = DnsHelper::getModel($aid);
        $result = $dns->getDomainList($kw, $page, $pagesize);
        if(!$result) return json(['code'=>-1, 'msg'=>'获取域名列表失败，'.$dns->getError()]);

        $newlist = [];
        foreach($result['list'] as $row){
            if(!Db::name('domain')->where('aid', $aid)->where('name', $row['Domain'])->find()){
                $newlist[] = $row;
            }
        }
        return json(['code'=>0, 'data'=>['total'=>$result['total'], 'list'=>$newlist]]);
    }

    //获取解析线路和最小TTL
    private function get_line_and_ttl($drow){
        $recordLine = cache('record_line_'.$drow['id']);
        $minTTL = cache('min_ttl_'.$drow['id']);
        if(empty($recordLine)){
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            if(!$dns) return $this->alert('error', 'DNS模块不存在');
            $recordLine = $dns->getRecordLine();
            if(!$recordLine) return $this->alert('error', '获取解析线路列表失败，'.$dns->getError());
            cache('record_line_'.$drow['id'], $recordLine, 604800);
            $minTTL = $dns->getMinTTL();
            if($minTTL){
                cache('min_ttl_'.$drow['id'], $minTTL, 604800);
            }
        }
        return [$recordLine, $minTTL];
    }

    public function domain_info(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach($recordLine as $key=>$item){
            $recordLineArr[] = ['id'=>strval($key), 'name'=>$item['name'], 'parent'=>$item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        $drow['config'] = $dnsconfig;
        $drow['recordLine'] = $recordLineArr;
        $drow['minTTL'] = $minTTL?$minTTL:1;
        if(input('?post.loginurl') && input('post.loginurl') == '1'){
            $token = getSid();
            cache('quicklogin_'.$drow['name'], $token, 3600);
            $timestamp = time();
            $sign = md5(env('app.sys_key').$drow['name'].$timestamp.$token.env('app.sys_key'));
            $drow['loginurl'] = request()->root(true).'/quicklogin?domain='.$drow['name'].'&timestamp='.$timestamp.'&token='.$token.'&sign='.$sign;
        }

        return json(['code'=>0, 'data'=>$drow]);
    }

    public function record(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach($recordLine as $key=>$item){
            $recordLineArr[] = ['id'=>strval($key), 'name'=>$item['name'], 'parent'=>$item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('minTTL', $minTTL?$minTTL:1);
        View::assign('dnsconfig', $dnsconfig);
        return view();
    }

    public function record_data(){
        $id = input('param.id/d');
        $keyword = input('post.keyword', null, 'trim');
        $subdomain = input('post.subdomain', null, 'trim');
        $type = input('post.type', null, 'trim');
        $line = input('post.line', null, 'trim');
        $status = input('post.status', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        if($limit == 0){
            $page = 1;
        }else{
            $page = $offset/$limit + 1;
        }

        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['total'=>0, 'rows'=>[]]);
        }
        if(!checkPermission(0, $drow['name'])) return json(['total'=>0, 'rows'=>[]]);

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $domainRecords = $dns->getDomainRecords($page, $limit, $keyword, $subdomain, $type, $line, $status);
        if(!$domainRecords) return json(['total'=>0, 'rows'=>[]]);

        if($domainRecords['total'] != $drow['recordcount']){
            Db::name('domain')->where('id', $id)->update(['recordcount'=>$domainRecords['total']]);
        }

        $recordLine = cache('record_line_'.$id);

        foreach($domainRecords['list'] as &$row){
            $row['LineName'] = isset($recordLine[$row['Line']]) ? $recordLine[$row['Line']]['name'] : $row['Line'];
        }

        return json(['total'=>$domainRecords['total'], 'rows'=>$domainRecords['list']]);
    }

    public function record_list(){
        if(!checkPermission(2)) return $this->alert('error', '无权限');
        $id = input('post.id/d');
        $rr = input('post.rr', null, 'trim');

        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['code'=>-1, 'msg'=>'域名不存在']);
        }
        if(!checkPermission(0, $drow['name'])) return json(['code'=>-1, 'msg'=>'无权限']);

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $domainRecords = $dns->getSubDomainRecords($rr, 1, 100);
        if(!$domainRecords) return json(['code'=>-1, 'msg'=>'获取记录列表失败，'.$dns->getError()]);

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        foreach($domainRecords['list'] as &$row){
            $row['LineName'] = isset($recordLine[$row['Line']]) ? $recordLine[$row['Line']]['name'] : $row['Line'];
        }

        return json(['code'=>0, 'data'=>$domainRecords['list']]);
    }

    public function record_add(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['code'=>-1, 'msg'=>'域名不存在']);
        }
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $name = input('post.name', null, 'trim');
        $type = input('post.type', null, 'trim');
        $value = input('post.value', null, 'trim');
        $line = input('post.line', null, 'trim');
        $ttl = input('post.ttl/d', 600);
        $mx = input('post.mx/d', 1);
        $remark = input('post.remark', null, 'trim');

        if(empty($name) || empty($type) || empty($value)){
            return json(['code'=>-1, 'msg'=>'参数不能为空']);
        }
        
        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $recordid = $dns->addDomainRecord($name, $type, $value, $line, $ttl, $mx, $remark);
        if($recordid){
            $this->add_log($drow['name'], '添加解析', $type.'记录 '.$name.' '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
            return json(['code'=>0, 'msg'=>'添加解析记录成功！']);
        }else{
            return json(['code'=>-1, 'msg'=>'添加解析记录失败，'.$dns->getError()]);
        }
    }

    public function record_update(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['code'=>-1, 'msg'=>'域名不存在']);
        }
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $name = input('post.name', null, 'trim');
        $type = input('post.type', null, 'trim');
        $value = input('post.value', null, 'trim');
        $line = input('post.line', null, 'trim');
        $ttl = input('post.ttl/d', 600);
        $mx = input('post.mx/d', 1);
        $remark = input('post.remark', null, 'trim');

        if(empty($recordid) || empty($name) || empty($type) || empty($value)){
            return json(['code'=>-1, 'msg'=>'参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $recordid = $dns->updateDomainRecord($recordid, $name, $type, $value, $line, $ttl, $mx, $remark);
        if($recordid){
            $this->add_log($drow['name'], '修改解析', $type.'记录 '.$name.' '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
            return json(['code'=>0, 'msg'=>'修改解析记录成功！']);
        }else{
            return json(['code'=>-1, 'msg'=>'修改解析记录失败，'.$dns->getError()]);
        }
    }

    public function record_delete(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['code'=>-1, 'msg'=>'域名不存在']);
        }
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');

        if(empty($recordid)){
            return json(['code'=>-1, 'msg'=>'参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if($dns->deleteDomainRecord($recordid)){
            $this->add_log($drow['name'], '删除解析', '记录ID:'.$recordid);
            return json(['code'=>0, 'msg'=>'删除解析记录成功！']);
        }else{
            return json(['code'=>-1, 'msg'=>'删除解析记录失败，'.$dns->getError()]);
        }
    }

    public function record_status(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['code'=>-1, 'msg'=>'域名不存在']);
        }
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $status = input('post.status', null, 'trim');

        if(empty($recordid)){
            return json(['code'=>-1, 'msg'=>'参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if($dns->setDomainRecordStatus($recordid, $status)){
            $action = $status == '1' ? '启用解析' : '暂停解析';
            $this->add_log($drow['name'], $action, '记录ID:'.$recordid);
            return json(['code'=>0, 'msg'=>'操作成功！']);
        }else{
            return json(['code'=>-1, 'msg'=>'操作失败，'.$dns->getError()]);
        }
    }

    public function record_remark(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['code'=>-1, 'msg'=>'域名不存在']);
        }
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $remark = input('post.remark', null, 'trim');

        if(empty($recordid)){
            return json(['code'=>-1, 'msg'=>'参数不能为空']);
        }
        if(empty($remark)) $remark = null;

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if($dns->updateDomainRecordRemark($recordid, $remark)){
            return json(['code'=>0, 'msg'=>'操作成功！']);
        }else{
            return json(['code'=>-1, 'msg'=>'操作失败，'.$dns->getError()]);
        }
    }

    public function record_batch(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['code'=>-1, 'msg'=>'域名不存在']);
        }
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordids = input('post.recordids', null, 'trim');
        $action = input('post.action', null, 'trim');

        if(empty($recordids) || empty($action)){
            return json(['code'=>-1, 'msg'=>'参数不能为空']);
        }

        $success = 0;
        $fail = 0;
        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if($action == 'open'){
            foreach($recordids as $recordid){
                if($dns->setDomainRecordStatus($recordid, '1')){
                    $this->add_log($drow['name'], '启用解析', '记录ID:'.$recordid);
                    $success++;
                }
            }
            $msg = '成功启用'.$success.'条解析记录';
        }else if($action == 'pause'){
            foreach($recordids as $recordid){
                if($dns->setDomainRecordStatus($recordid, '0')){
                    $this->add_log($drow['name'], '暂停解析', '记录ID:'.$recordid);
                    $success++;
                }
            }
            $msg = '成功暂停'.$success.'条解析记录';
        }else if($action == 'delete'){
            foreach($recordids as $recordid){
                if($dns->deleteDomainRecord($recordid)){
                    $this->add_log($drow['name'], '删除解析', '记录ID:'.$recordid);
                    $success++;
                }
            }
            $msg = '成功删除'.$success.'条解析记录';
        }else if($action == 'remark'){
            $remark = input('post.remark', null, 'trim');
            if(empty($remark)) $remark = null;
            foreach($recordids as $recordid){
                if($dns->updateDomainRecordRemark($recordid, $remark)){
                    $success++;
                }else{
                    $fail++;
                }
            }
            $msg = '批量修改备注，成功'.$success.'条，失败'.$fail.'条';
        }
        return json(['code'=>0, 'msg'=>$msg]);
    }

    public function record_batch_edit(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return json(['code'=>-1, 'msg'=>'域名不存在']);
        }
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $action = input('post.action', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');
        $recordinfo = json_decode($recordinfo, true);

        if($action == 'value'){
            $type = input('post.type', null, 'trim');
            $value = input('post.value', null, 'trim');
    
            if(empty($recordinfo) || empty($type) || empty($value)){
                return json(['code'=>-1, 'msg'=>'参数不能为空']);
            }
    
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
    
            $success = 0; $fail = 0;
            foreach($recordinfo as $record){
                $recordid = $dns->updateDomainRecord($record['recordid'], $record['name'], $type, $value, $record['line'], $record['ttl'], $record['mx'], $record['remark']);
                if($recordid){
                    $this->add_log($drow['name'], '修改解析', $type.'记录 '.$record['name'].' '.$value.' (线路:'.$record['line'].' TTL:'.$record['ttl'].')');
                    $success++;
                }else{
                    $fail++;
                }
            }
            return json(['code'=>0, 'msg'=>'批量修改解析记录，成功'.$success.'条，失败'.$fail.'条']);

        }else if($action == 'line'){
            $line = input('post.line', null, 'trim');

            if(empty($recordinfo) || empty($line)){
                return json(['code'=>-1, 'msg'=>'参数不能为空']);
            }

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
    
            $success = 0; $fail = 0;
            foreach($recordinfo as $record){
                $recordid = $dns->updateDomainRecord($record['recordid'], $record['name'], $record['type'], $record['value'], $line, $record['ttl'], $record['mx'], $record['remark']);
                if($recordid){
                    $this->add_log($drow['name'], '修改解析', $record['type'].'记录 '.$record['name'].' '.$record['value'].' (线路:'.$line.' TTL:'.$record['ttl'].')');
                    $success++;
                }else{
                    $fail++;
                }
            }
            return json(['code'=>0, 'msg'=>'批量修改解析线路，成功'.$success.'条，失败'.$fail.'条']);
        }
    }

    public function record_batch_add(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        if(request()->isAjax()){
            $record = input('post.record', null, 'trim');
            $type = input('post.type', null, 'trim');
            $line = input('post.line', null, 'trim');
            $ttl = input('post.ttl/d', 600);
            $mx = input('post.mx/d', 1);
            $recordlist = explode("\n", $record);

            if(empty($record) || empty($recordlist)){
                return json(['code'=>-1, 'msg'=>'参数不能为空']);
            }
            
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);

            $success = 0; $fail = 0;
            foreach($recordlist as $record){
                $record = trim($record);
                $arr = explode(' ', $record);
                if(empty($record) || empty($arr[0]) || empty($arr[1])) continue;
                $thistype = empty($type) ? getDnsType($arr[1]) : $type;
                $recordid = $dns->addDomainRecord($arr[0], $thistype, $arr[1], $line, $ttl, $mx);
                if($recordid){
                    $this->add_log($drow['name'], '添加解析', $thistype.'记录 '.$arr[0].' '.$arr[1].' (线路:'.$line.' TTL:'.$ttl.')');
                    $success++;
                }else{
                    $fail++;
                }
            }
            return json(['code'=>0, 'msg'=>'批量添加解析，成功'.$success.'条，失败'.$fail.'条']);
        }

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach($recordLine as $key=>$item){
            $recordLineArr[] = ['id'=>strval($key), 'name'=>$item['name'], 'parent'=>$item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('minTTL', $minTTL?$minTTL:1);
        View::assign('dnsconfig', $dnsconfig);
        return view('batchadd');
    }

    public function record_log(){
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if(!$drow){
            return $this->alert('error', '域名不存在');
        }
        if(!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        if(request()->isPost()){
            $offset = input('post.offset/d');
            $limit = input('post.limit/d');
            $page = $offset/$limit + 1;
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            $domainRecords = $dns->getDomainRecordLog($page, $limit);
            if(!$domainRecords) return json(['total'=>0, 'rows'=>[]]);
            return json(['total'=>$domainRecords['total'], 'rows'=>$domainRecords['list']]);
        }

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        return view('log');
    }

    private function add_log($domain, $action, $data){
        Db::name('log')->insert(['uid' => request()->user['id'], 'domain' => $domain, 'action' => $action, 'data' => $data, 'addtime' => date("Y-m-d H:i:s")]);
    }
}