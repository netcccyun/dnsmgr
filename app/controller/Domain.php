<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;
use app\lib\DnsHelper;
use app\service\ExpireNoticeService;
use Exception;

class Domain extends BaseController
{

    public function account()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        View::assign('dnsconfig', DnsHelper::$dns_config);
        return view();
    }

    public function account_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $kw = $this->request->post('kw', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('account');
        if (!empty($kw)) {
            $select->whereLike('ak|remark', '%' . $kw . '%');
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            $row['typename'] = DnsHelper::$dns_config[$row['type']]['name'];
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function account_op()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $act = input('param.act');
        if ($act == 'get') {
            $id = input('post.id/d');
            $row = Db::name('account')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '域名账户不存在']);
            return json(['code' => 0, 'data' => $row]);
        } elseif ($act == 'add') {
            $type = input('post.type');
            $ak = input('post.ak', null, 'trim');
            $sk = input('post.sk', null, 'trim');
            $ext = input('post.ext', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            $proxy = input('post.proxy/d', 0);
            if (empty($ak) || empty($sk)) return json(['code' => -1, 'msg' => 'AccessKey和SecretKey不能为空']);
            if (Db::name('account')->where('type', $type)->where('ak', $ak)->find()) {
                return json(['code' => -1, 'msg' => '域名账户已存在']);
            }
            Db::startTrans();
            $id = Db::name('account')->insertGetId([
                'type' => $type,
                'ak' => $ak,
                'sk' => $sk,
                'ext' => $ext,
                'proxy' => $proxy,
                'remark' => $remark,
                'addtime' => date('Y-m-d H:i:s'),
            ]);
            $dns = DnsHelper::getModel($id);
            if ($dns) {
                if ($dns->check()) {
                    Db::commit();
                    return json(['code' => 0, 'msg' => '添加域名账户成功！']);
                } else {
                    Db::rollback();
                    return json(['code' => -1, 'msg' => '验证域名账户失败，' . $dns->getError()]);
                }
            } else {
                Db::rollback();
                return json(['code' => -1, 'msg' => 'DNS模块(' . $type . ')不存在']);
            }
        } elseif ($act == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('account')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '域名账户不存在']);
            $type = input('post.type');
            $ak = input('post.ak', null, 'trim');
            $sk = input('post.sk', null, 'trim');
            $ext = input('post.ext', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            $proxy = input('post.proxy/d', 0);
            if (empty($ak) || empty($sk)) return json(['code' => -1, 'msg' => 'AccessKey和SecretKey不能为空']);
            if (Db::name('account')->where('type', $type)->where('ak', $ak)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => '域名账户已存在']);
            }
            Db::startTrans();
            Db::name('account')->where('id', $id)->update([
                'type' => $type,
                'ak' => $ak,
                'sk' => $sk,
                'ext' => $ext,
                'proxy' => $proxy,
                'remark' => $remark,
            ]);
            $dns = DnsHelper::getModel($id);
            if ($dns) {
                if ($dns->check()) {
                    Db::commit();
                    return json(['code' => 0, 'msg' => '修改域名账户成功！']);
                } else {
                    Db::rollback();
                    return json(['code' => -1, 'msg' => '验证域名账户失败，' . $dns->getError()]);
                }
            } else {
                Db::rollback();
                return json(['code' => -1, 'msg' => 'DNS模块(' . $type . ')不存在']);
            }
        } elseif ($act == 'del') {
            $id = input('post.id/d');
            $dcount = DB::name('domain')->where('aid', $id)->count();
            if ($dcount > 0) return json(['code' => -1, 'msg' => '该域名账户下存在域名，无法删除']);
            Db::name('account')->where('id', $id)->delete();
            return json(['code' => 0]);
        }
        return json(['code' => -3]);
    }


    public function domain()
    {
        if (request()->user['type'] == 'domain') {
            return redirect('/record/' . request()->user['id']);
        }
        $list = Db::name('account')->select();
        $accounts = [];
        $types = [];
        foreach ($list as $row) {
            $accounts[$row['id']] = $row['id'] . '_' . DnsHelper::$dns_config[$row['type']]['name'];
            if (!array_key_exists($row['type'], $types)) {
                $types[$row['type']] = DnsHelper::$dns_config[$row['type']]['name'];
            }
            if (!empty($row['remark'])) {
                $accounts[$row['id']] .= '（' . $row['remark'] . '）';
            }
        }
        View::assign('accounts', $accounts);
        View::assign('types', $types);
        return view();
    }

    public function domain_add()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $list = Db::name('account')->select();
        $accounts = [];
        $types = [];
        foreach ($list as $row) {
            $accounts[$row['id']] = $row['id'] . '_' . DnsHelper::$dns_config[$row['type']]['name'];
            if (!array_key_exists($row['type'], $types)) {
                $types[$row['type']] = DnsHelper::$dns_config[$row['type']]['name'];
            }
            if (!empty($row['remark'])) {
                $accounts[$row['id']] .= '（' . $row['remark'] . '）';
            }
        }
        View::assign('accounts', $accounts);
        View::assign('types', $types);
        return view();
    }

    public function domain_data()
    {
        if (!checkPermission(1)) return json(['total' => 0, 'rows' => []]);
        $kw = input('post.kw', null, 'trim');
        $type = input('post.type', null, 'trim');
        $status = input('post.status', null, 'trim');
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);

        $select = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id');
        if (!empty($kw)) {
            $select->whereLike('name|A.remark', '%' . $kw . '%');
        }
        if (!empty($type)) {
            $select->whereLike('B.type', $type);
        }
        if (request()->user['level'] == 1) {
            $select->where('is_hide', 0)->where('A.name', 'in', request()->user['permission']);
        }
        if (!isNullOrEmpty($status)) {
            if ($status == '2') {
                $select->where('A.expiretime', '<=', date('Y-m-d H:i:s'));
            } elseif ($status == '1') {
                $select->where('A.expiretime', '<=', date('Y-m-d H:i:s', time() + 86400 * 30))->where('A.expiretime', '>', date('Y-m-d H:i:s'));
            }
        }
        $total = $select->count();
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark')->order('A.id', 'desc')->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            $row['typename'] = DnsHelper::$dns_config[$row['type']]['name'];
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function domain_op()
    {
        if (!checkPermission(1)) return $this->alert('error', '无权限');
        $act = input('param.act');
        if ($act == 'get') {
            $id = input('post.id/d');
            $row = Db::name('domain')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '域名不存在']);
            return json(['code' => 0, 'data' => $row]);
        } elseif ($act == 'add') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $aid = input('post.aid/d');
            $name = input('post.name', null, 'trim');
            $thirdid = input('post.thirdid', null, 'trim');
            $recordcount = input('post.recordcount/d', 0);
            if (empty($name) || empty($thirdid)) return json(['code' => -1, 'msg' => '参数不能为空']);
            if (Db::name('domain')->where('aid', $aid)->where('name', $name)->find()) {
                return json(['code' => -1, 'msg' => '域名已存在']);
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
            return json(['code' => 0, 'msg' => '添加域名成功！']);
        } elseif ($act == 'edit') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $id = input('post.id/d');
            $row = Db::name('domain')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => '域名不存在']);
            $is_hide = input('post.is_hide/d');
            $is_sso = input('post.is_sso/d');
            $is_notice = input('post.is_notice/d');
            $expiretime = input('post.expiretime', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if (empty($remark)) $remark = null;
            Db::name('domain')->where('id', $id)->update([
                'is_hide' => $is_hide,
                'is_sso' => $is_sso,
                'is_notice' => $is_notice,
                'expiretime' => $expiretime ? $expiretime : null,
                'remark' => $remark,
            ]);
            return json(['code' => 0, 'msg' => '修改域名配置成功！']);
        } elseif ($act == 'del') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $id = input('post.id/d');
            Db::name('domain')->where('id', $id)->delete();
            Db::name('dmtask')->where('did', $id)->delete();
            Db::name('optimizeip')->where('did', $id)->delete();
            return json(['code' => 0]);
        } elseif ($act == 'batchadd') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $aid = input('post.aid/d');
            $domains = input('post.domains');
            if (empty($domains)) return json(['code' => -1, 'msg' => '参数不能为空']);
            $data = [];
            foreach ($domains as $row) {
                $data[] = [
                    'aid' => $aid,
                    'name' => $row['name'],
                    'thirdid' => $row['id'],
                    'addtime' => date('Y-m-d H:i:s'),
                    'is_hide' => 0,
                    'is_sso' => 1,
                    'recordcount' => $row['recordcount'],
                ];
            }
            Db::name('domain')->insertAll($data);
            return json(['code' => 0, 'msg' => '成功添加' . count($data) . '个域名！']);
        } elseif ($act == 'batchedit') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $ids = input('post.ids');
            if (empty($ids)) return json(['code' => -1, 'msg' => '参数不能为空']);
            $remark = input('post.remark', null, 'trim');
            if (empty($remark)) $remark = null;
            $count = Db::name('domain')->where('id', 'in', $ids)->update(['remark' => $remark]);
            return json(['code' => 0, 'msg' => '成功修改' . $count . '个域名！']);
        } elseif ($act == 'batchsetnotice') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $ids = input('post.ids');
            $is_notice = input('post.is_notice/d', 0);
            if (empty($ids)) return json(['code' => -1, 'msg' => '参数不能为空']);
            $count = Db::name('domain')->where('id', 'in', $ids)->update(['is_notice' => $is_notice]);
            return json(['code' => 0, 'msg' => '成功修改' . $count . '个域名！']);
        } elseif ($act == 'batchdel') {
            if (!checkPermission(2)) return $this->alert('error', '无权限');
            $ids = input('post.ids');
            if (empty($ids)) return json(['code' => -1, 'msg' => '参数不能为空']);
            Db::name('domain')->where('id', 'in', $ids)->delete();
            Db::name('dmtask')->where('did', 'in', $ids)->delete();
            Db::name('optimizeip')->where('did', 'in', $ids)->delete();
            return json(['code' => 0, 'msg' => '成功删除' . count($ids) . '个域名！']);
        }
        return json(['code' => -3]);
    }

    public function domain_list()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $aid = input('post.aid/d');
        $kw = input('post.kw', null, 'trim');
        $page = input('?post.page') ? input('post.page/d') : 1;
        $pagesize = input('?post.pagesize') ? input('post.pagesize/d') : 10;
        $dns = DnsHelper::getModel($aid);
        $result = $dns->getDomainList($kw, $page, $pagesize);
        if (!$result) return json(['code' => -1, 'msg' => '获取域名列表失败，' . $dns->getError()]);

        foreach ($result['list'] as &$row) {
            $row['disabled'] = Db::name('domain')->where('aid', $aid)->where('name', $row['Domain'])->find() != null;
        }
        return json(['code' => 0, 'data' => ['total' => $result['total'], 'list' => $result['list']]]);
    }

    //获取解析线路和最小TTL
    private function get_line_and_ttl($drow)
    {
        $recordLine = cache('record_line_' . $drow['id']);
        $minTTL = cache('min_ttl_' . $drow['id']);
        if (empty($recordLine)) {
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            if (!$dns) throw new Exception('DNS模块不存在');
            $recordLine = $dns->getRecordLine();
            if (!$recordLine) throw new Exception('获取解析线路列表失败，' . $dns->getError());
            cache('record_line_' . $drow['id'], $recordLine, 604800);
            $minTTL = $dns->getMinTTL();
            if ($minTTL) {
                cache('min_ttl_' . $drow['id'], $minTTL, 604800);
            }
        }
        return [$recordLine, $minTTL];
    }

    public function domain_info()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        $drow['config'] = $dnsconfig;
        $drow['recordLine'] = $recordLineArr;
        $drow['minTTL'] = $minTTL ? $minTTL : 1;
        if (input('?post.loginurl') && input('post.loginurl') == '1') {
            $token = getSid();
            cache('quicklogin_' . $drow['name'], $token, 3600);
            $timestamp = time();
            $sign = md5(config_get('sys_key') . $drow['name'] . $timestamp . $token . config_get('sys_key'));
            $drow['loginurl'] = request()->root(true) . '/quicklogin?domain=' . $drow['name'] . '&timestamp=' . $timestamp . '&token=' . $token . '&sign=' . $sign;
        }

        return json(['code' => 0, 'data' => $drow]);
    }

    public function record()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('minTTL', $minTTL ? $minTTL : 1);
        View::assign('dnsconfig', $dnsconfig);
        return view();
    }

    public function record_data()
    {
        $id = input('param.id/d');
        $keyword = input('post.keyword', null, 'trim');
        $subdomain = input('post.subdomain', null, 'trim');
        $value = input('post.value', null, 'trim');
        $type = input('post.type', null, 'trim');
        $line = input('post.line', null, 'trim');
        $status = input('post.status', null, 'trim');
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);
        if ($limit == 0) {
            $page = 1;
        } else {
            $page = $offset / $limit + 1;
        }

        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['total' => 0, 'rows' => []]);
        }
        if (!checkPermission(0, $drow['name'])) return json(['total' => 0, 'rows' => []]);

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $domainRecords = $dns->getDomainRecords($page, $limit, $keyword, $subdomain, $value, $type, $line, $status);
        if (!$domainRecords) return json(['total' => 0, 'rows' => []]);

        if (empty($keyword) && empty($subdomain) && empty($type) && isNullOrEmpty($line) && empty($status) && empty($value) && $domainRecords['total'] != $drow['recordcount']) {
            Db::name('domain')->where('id', $id)->update(['recordcount' => $domainRecords['total']]);
        }

        $recordLine = cache('record_line_' . $id);

        foreach ($domainRecords['list'] as &$row) {
            $row['LineName'] = isset($recordLine[$row['Line']]) ? $recordLine[$row['Line']]['name'] : $row['Line'];
        }

        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (DnsHelper::$dns_config[$dnstype]['page']) {
            return json($domainRecords['list']);
        }

        return json(['total' => $domainRecords['total'], 'rows' => $domainRecords['list']]);
    }

    public function record_list()
    {
        $id = input('post.id/d');
        $rr = input('post.rr', null, 'trim');

        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $domainRecords = $dns->getSubDomainRecords($rr, 1, 100);
        if (!$domainRecords) return json(['code' => -1, 'msg' => '获取记录列表失败，' . $dns->getError()]);

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $list = [];
        foreach ($domainRecords['list'] as &$row) {
            if ($rr == '@' && ($row['Type'] == 'NS' || $row['Type'] == 'SOA')) continue;
            $row['LineName'] = isset($recordLine[$row['Line']]) ? $recordLine[$row['Line']]['name'] : $row['Line'];
            $list[] = $row;
        }

        return json(['code' => 0, 'data' => $list]);
    }

    public function record_add()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $name = input('post.name', null, 'trim');
        $type = input('post.type', null, 'trim');
        $value = input('post.value', null, 'trim');
        $line = input('post.line', null, 'trim');
        $ttl = input('post.ttl/d', 600);
        $weight = input('post.weight/d', 0);
        $mx = input('post.mx/d', 1);
        $remark = input('post.remark', null, 'trim');

        if (empty($name) || empty($type) || empty($value)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $recordid = $dns->addDomainRecord($name, $type, $value, $line, $ttl, $mx, $weight, $remark);
        if ($recordid) {
            $this->add_log($drow['name'], '添加解析', $name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
            return json(['code' => 0, 'msg' => '添加解析记录成功！']);
        } else {
            return json(['code' => -1, 'msg' => '添加解析记录失败，' . $dns->getError()]);
        }
    }

    public function record_update()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $name = input('post.name', null, 'trim');
        $type = input('post.type', null, 'trim');
        $value = input('post.value', null, 'trim');
        $line = input('post.line', null, 'trim');
        $ttl = input('post.ttl/d', 600);
        $weight = input('post.weight/d', 0);
        $mx = input('post.mx/d', 1);
        $remark = input('post.remark', null, 'trim');

        $recordinfo = input('post.recordinfo', null, 'trim');
        $recordinfo = json_decode($recordinfo, true);

        if (empty($recordid) || empty($name) || empty($type) || empty($value)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $recordid = $dns->updateDomainRecord($recordid, $name, $type, $value, $line, $ttl, $mx, $weight, $remark);
        if ($recordid) {
            if ($recordinfo) {
                if (is_array($recordinfo['Value'])) $recordinfo['Value'] = implode(',', $recordinfo['Value']);
                if ($recordinfo['Name'] != $name || $recordinfo['Type'] != $type || $recordinfo['Value'] != $value) {
                    $this->add_log($drow['name'], '修改解析', $recordinfo['Name'].' ['.$recordinfo['Type'].'] '.$recordinfo['Value'].' → '.$name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
                } elseif($recordinfo['Line'] != $line || $recordinfo['TTL'] != $ttl) {
                    $this->add_log($drow['name'], '修改解析', $name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
                }
            } else {
                $this->add_log($drow['name'], '修改解析', $name.' ['.$type.'] '.$value.' (线路:'.$line.' TTL:'.$ttl.')');
            }
            return json(['code' => 0, 'msg' => '修改解析记录成功！']);
        } else {
            return json(['code' => -1, 'msg' => '修改解析记录失败，' . $dns->getError()]);
        }
    }

    public function record_delete()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');
        $recordinfo = json_decode($recordinfo, true);

        if (empty($recordid)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if ($dns->deleteDomainRecord($recordid)) {
            if ($recordinfo) {
                if (is_array($recordinfo['Value'])) $recordinfo['Value'] = implode(',', $recordinfo['Value']);
                $this->add_log($drow['name'], '删除解析', $recordinfo['Name'].' ['.$recordinfo['Type'].'] '.$recordinfo['Value'].' (线路:'.$recordinfo['Line'].' TTL:'.$recordinfo['TTL'].')');
            } else {
                $this->add_log($drow['name'], '删除解析', '记录ID:'.$recordid);
            }
            return json(['code' => 0, 'msg' => '删除解析记录成功！']);
        } else {
            return json(['code' => -1, 'msg' => '删除解析记录失败，' . $dns->getError()]);
        }
    }

    public function record_status()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $status = input('post.status', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');
        $recordinfo = json_decode($recordinfo, true);

        if (empty($recordid)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if ($dns->setDomainRecordStatus($recordid, $status)) {
            $action = $status == '1' ? '启用解析' : '暂停解析';
            if ($recordinfo) {
                if (is_array($recordinfo['Value'])) $recordinfo['Value'] = implode(',', $recordinfo['Value']);
                $this->add_log($drow['name'], $action, $recordinfo['Name'].' ['.$recordinfo['Type'].'] '.$recordinfo['Value'].' (线路:'.$recordinfo['Line'].' TTL:'.$recordinfo['TTL'].')');
            } else {
                $this->add_log($drow['name'], $action, '记录ID:'.$recordid);
            }
            return json(['code' => 0, 'msg' => '操作成功！']);
        } else {
            return json(['code' => -1, 'msg' => '操作失败，' . $dns->getError()]);
        }
    }

    public function record_remark()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $recordid = input('post.recordid', null, 'trim');
        $remark = input('post.remark', null, 'trim');

        if (empty($recordid)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }
        if (empty($remark)) $remark = null;

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if ($dns->updateDomainRecordRemark($recordid, $remark)) {
            return json(['code' => 0, 'msg' => '操作成功！']);
        } else {
            return json(['code' => -1, 'msg' => '操作失败，' . $dns->getError()]);
        }
    }

    public function record_batch()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $action = input('post.action', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');
        $recordinfo = json_decode($recordinfo, true);

        if (empty($recordinfo) || empty($action)) {
            return json(['code' => -1, 'msg' => '参数不能为空']);
        }

        $success = 0;
        $fail = 0;
        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        if ($action == 'open') {
            foreach ($recordinfo as $record) {
                if ($dns->setDomainRecordStatus($record['RecordId'], '1')) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '启用解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                }
            }
            $msg = '成功启用' . $success . '条解析记录';
        } else if ($action == 'pause') {
            foreach ($recordinfo as $record) {
                if ($dns->setDomainRecordStatus($record['RecordId'], '0')) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '暂停解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                }
            }
            $msg = '成功暂停' . $success . '条解析记录';
        } else if ($action == 'delete') {
            foreach ($recordinfo as $record) {
                if ($dns->deleteDomainRecord($record['RecordId'])) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '删除解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                }
            }
            $msg = '成功删除' . $success . '条解析记录';
        } else if ($action == 'remark') {
            $remark = input('post.remark', null, 'trim');
            if (empty($remark)) $remark = null;
            foreach ($recordinfo as $record) {
                if ($dns->updateDomainRecordRemark($record['RecordId'], $remark)) {
                    $success++;
                } else {
                    $fail++;
                }
            }
            $msg = '批量修改备注，成功' . $success . '条，失败' . $fail . '条';
        }
        return json(['code' => 0, 'msg' => $msg]);
    }

    public function record_batch_edit()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        $action = input('post.action', null, 'trim');
        $recordinfo = input('post.recordinfo', null, 'trim');
        $recordinfo = json_decode($recordinfo, true);

        if ($action == 'value') {
            $type = input('post.type', null, 'trim');
            $value = input('post.value', null, 'trim');

            if (empty($recordinfo) || empty($type) || empty($value)) {
                return json(['code' => -1, 'msg' => '参数不能为空']);
            }

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);

            $success = 0;
            $fail = 0;
            foreach ($recordinfo as $record) {
                $recordid = $dns->updateDomainRecord($record['RecordId'], $record['Name'], $type, $value, $record['Line'], $record['TTL'], $record['MX'], $record['Weight'], $record['Remark']);
                if ($recordid) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '修改解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' → '.$record['Name'].' ['.$type.'] '.$value.' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                } else {
                    $fail++;
                }
            }
            return json(['code' => 0, 'msg' => '批量修改解析记录，成功' . $success . '条，失败' . $fail . '条']);
        } else if ($action == 'line') {
            $line = input('post.line', null, 'trim');

            if (empty($recordinfo) || isNullOrEmpty($line)) {
                return json(['code' => -1, 'msg' => '参数不能为空']);
            }

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);

            $success = 0;
            $fail = 0;
            foreach ($recordinfo as $record) {
                $recordid = $dns->updateDomainRecord($record['RecordId'], $record['Name'], $record['Type'], $record['Value'], $line, $record['TTL'], $record['MX'], $record['Weight'], $record['Remark']);
                if ($recordid) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '修改解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' (线路:'.$line.' TTL:'.$record['TTL'].')');
                    $success++;
                } else {
                    $fail++;
                }
            }
            return json(['code' => 0, 'msg' => '批量修改解析线路，成功' . $success . '条，失败' . $fail . '条']);
        }
    }

    public function record_batch_add()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        if (request()->isAjax()) {
            $record = input('post.record', null, 'trim');
            $type = input('post.type', null, 'trim');
            $line = input('post.line', null, 'trim');
            $ttl = input('post.ttl/d', 600);
            $mx = input('post.mx/d', 1);
            $recordlist = explode("\n", $record);

            if (empty($record) || empty($recordlist)) {
                return json(['code' => -1, 'msg' => '参数不能为空']);
            }
            if (is_null($line)) {
                $line = DnsHelper::$line_name[$dnstype]['DEF'];
                if ($dnstype == 'cloudflare' && input('post.proxy/d', 0) == 1) {
                    $line = '1';
                }
            }

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);

            $success = 0;
            $fail = 0;
            foreach ($recordlist as $record) {
                $record = trim($record);
                $arr = explode(' ', $record);
                if (empty($record) || empty($arr[0]) || empty($arr[1])) continue;
                $thistype = empty($type) ? getDnsType($arr[1]) : $type;
                $recordid = $dns->addDomainRecord($arr[0], $thistype, $arr[1], $line, $ttl, $mx);
                if ($recordid) {
                    $this->add_log($drow['name'], '添加解析', $arr[0].' ['.$thistype.'] '.$arr[1].' (线路:'.$line.' TTL:'.$ttl.')');
                    $success++;
                } else {
                    $fail++;
                }
            }
            if ($success > 0) {
                return json(['code' => 0, 'msg' => '批量添加解析，成功' . $success . '条，失败' . $fail . '条']);
            } elseif($fail > 0) {
                return json(['code' => -1, 'msg' => '批量添加解析失败，' . $dns->getError()]);
            } else {
                return json(['code' => -1, 'msg' => '批量添加解析失败，没有可添加的记录']);
            }
        }

        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('minTTL', $minTTL ? $minTTL : 1);
        View::assign('dnsconfig', $dnsconfig);
        return view('batchadd');
    }

    public function record_batch_add2()
    {
        return view('batchadd2');
    }

    public function record_batch_edit2()
    {
        if (request()->isAjax()) {
            $id = input('post.id/d');
            $drow = Db::name('domain')->where('id', $id)->find();
            if (!$drow) {
                return json(['code' => -1, 'msg' => '域名不存在']);
            }
            $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
            if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);

            $name = input('post.name', null, 'trim');
            $type = input('post.type', null, 'trim');
            $value = input('post.value', null, 'trim');
            $ttl = input('post.ttl/d', 0);
            $mx = input('post.mx/d', 0);

            if (empty($name) || empty($type) || empty($value)) {
                return json(['code' => -1, 'msg' => '必填参数不能为空']);
            }
            $line = DnsHelper::$line_name[$dnstype]['DEF'];

            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            $domainRecords = $dns->getSubDomainRecords($name, 1, 100);
            if (!$domainRecords) return json(['code' => -1, 'msg' => '获取记录列表失败，' . $dns->getError()]);
            if (empty($domainRecords['list'])) return json(['code' => -1, 'msg' => '没有可修改的记录']);

            if ($type == 'A' || $type == 'AAAA' || $type == 'CNAME') {
                $list2 = array_filter($domainRecords['list'], function ($item) use ($type) {
                    return $item['Type'] == $type;
                });
                if (!empty($list2)) {
                    $list = $list2;
                } else {
                    $list = array_filter($domainRecords['list'], function ($item) {
                        return $item['Type'] == 'A' || $item['Type'] == 'AAAA' || $item['Type'] == 'CNAME';
                    });
                }
            } else {
                $list = array_filter($domainRecords['list'], function ($item) use ($type) {
                    return $item['Type'] == $type;
                });
            }
            if (empty($list)) return json(['code' => -1, 'msg' => '没有可修改的'.$type.'记录']);

            $list2 = array_filter($domainRecords['list'], function ($item) use ($line) {
                return $item['Line'] == $line;
            });
            if (!empty($list2)) $list = $list2;

            $success = 0;
            $fail = 0;
            foreach ($list as $record) {
                if ($name == '@' && ($record['Type'] == 'NS' || $record['Type'] == 'SOA')) continue;
                
                if ($ttl > 0) $record['TTL'] = $ttl;
                if ($mx > 0) $record['MX'] = $mx;
                $recordid = $dns->updateDomainRecord($record['RecordId'], $record['Name'], $type, $value, $record['Line'], $record['TTL'], $record['MX'], $record['Weight'], $record['Remark']);
                if ($recordid) {
                    if (is_array($record['Value'])) $record['Value'] = implode(',', $record['Value']);
                    $this->add_log($drow['name'], '修改解析', $record['Name'].' ['.$record['Type'].'] '.$record['Value'].' → '.$record['Name'].' ['.$type.'] '.$value.' (线路:'.$record['Line'].' TTL:'.$record['TTL'].')');
                    $success++;
                } else {
                    $fail++;
                }
            }
            if ($success > 0) {
                return json(['code' => 0, 'msg' => '成功修改' . $success . '条解析记录']);
            } elseif($fail > 0) {
                return json(['code' => -1, 'msg' => $dns->getError()]);
            } else {
                return json(['code' => -1, 'msg' => '没有可修改的记录']);
            }
        }

        return view('batchedit');
    }

    public function record_log()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');

        if (request()->isPost()) {
            $offset = input('post.offset/d');
            $limit = input('post.limit/d');
            $page = $offset / $limit + 1;
            $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
            $domainRecords = $dns->getDomainRecordLog($page, $limit);
            if (!$domainRecords) return json(['total' => 0, 'rows' => []]);
            return json(['total' => $domainRecords['total'], 'rows' => $domainRecords['list']]);
        }

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        return view('log');
    }

    private function add_log($domain, $action, $data)
    {
        if (strlen($data) > 500) $data = substr($data, 0, 500);
        Db::name('log')->insert(['uid' => request()->user['id'], 'domain' => $domain, 'action' => $action, 'data' => $data, 'addtime' => date("Y-m-d H:i:s")]);
    }


    public function weight()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return $this->alert('error', '域名不存在');
        }
        if (!checkPermission(0, $drow['name'])) return $this->alert('error', '无权限');
        if (request()->isAjax()) {
            $act = input('param.act');
            if ($act == 'status') {
                $subdomain = input('post.subdomain', null, 'trim');
                $status = input('post.status', null, 'trim');
                $type = input('post.type', null, 'trim');
                $line = input('post.line', null, 'trim');
                $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
                if ($dns->setWeightStatus($subdomain, $status, $type, $line)) {
                    return json(['code' => 0, 'msg' => '操作成功']);
                } else {
                    return json(['code' => -1, 'msg' => '操作失败，' . $dns->getError()]);
                }
            } elseif ($act == 'update') {
                $subdomain = input('post.subdomain', null, 'trim');
                $status = input('post.status', '0', 'trim');
                $type = input('post.type', null, 'trim');
                $line = input('post.line', null, 'trim');
                $weight = input('post.weight');
                if (empty($subdomain) || empty($type) || empty($line) || $status == '1' && empty($weight)) {
                    return json(['code' => -1, 'msg' => '参数不能为空']);
                }
                $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
                if ($type == 'CNAME' || $dns->setWeightStatus($subdomain, $status, $type, $line)) {
                    if ($status == '1') {
                        $success = 0;
                        foreach($weight as $recordid => $weight) {
                            if ($dns->updateRecordWeight($recordid, $weight)) {
                                $success++;
                            }
                        }
                        if ($success > 0) {
                            return json(['code' => 0, 'msg' => '成功修改' . $success . '条解析记录权重']);
                        } else {
                            return json(['code' => -1, 'msg' => '修改权重失败，' . $dns->getError()]);
                        }
                    }
                    return json(['code' => 0, 'msg' => '修改成功']);
                } else {
                    return json(['code' => -1, 'msg' => '修改失败，' . $dns->getError()]);
                }
            } else {
                return json(['code' => -1, 'msg' => '参数错误']);
            }
        }

        $dnstype = Db::name('account')->where('id', $drow['aid'])->value('type');
        if ($dnstype != 'aliyun') {
            return $this->alert('error', '仅支持阿里云解析的域名');
        }
        list($recordLine, $minTTL) = $this->get_line_and_ttl($drow);

        $recordLineArr = [];
        foreach ($recordLine as $key => $item) {
            $recordLineArr[] = ['id' => strval($key), 'name' => $item['name'], 'parent' => $item['parent']];
        }

        $dnsconfig = DnsHelper::$dns_config[$dnstype];
        $dnsconfig['type'] = $dnstype;

        View::assign('domainId', $id);
        View::assign('domainName', $drow['name']);
        View::assign('recordLine', $recordLineArr);
        View::assign('dnsconfig', $dnsconfig);
        return view();
    }

    public function weight_data()
    {
        $id = input('param.id/d');
        $keyword = input('post.keyword', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');
        if ($limit == 0) {
            $page = 1;
        } else {
            $page = $offset / $limit + 1;
        }

        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['total' => 0, 'rows' => []]);
        }
        if (!checkPermission(0, $drow['name'])) return json(['total' => 0, 'rows' => []]);

        $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
        $domainRecords = $dns->getWeightSubDomains($page, $limit, $keyword);
        return json(['total' => $domainRecords['total'], 'rows' => $domainRecords['list']]);
    }

    public function expire_notice()
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

    public function update_date()
    {
        $id = input('param.id/d');
        $drow = Db::name('domain')->where('id', $id)->find();
        if (!$drow) {
            return json(['code' => -1, 'msg' => '域名不存在']);
        }
        if (!checkPermission(0, $drow['name'])) return json(['code' => -1, 'msg' => '无权限']);
        $result = (new ExpireNoticeService())->updateDomainDate($id, $drow['name']);
        return json($result);
    }
}
