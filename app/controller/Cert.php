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
            $select->whereLike('name|remark', '%' . $kw . '%')->whereOr('id', $kw);
        }
        $total = $select->count();
        $rows = $select->order('id', 'desc')->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            if ($deploy == 1) {
                if (!empty($row['type']) && isset(DeployHelper::$deploy_config[$row['type']])) {
                    $row['typename'] = DeployHelper::$deploy_config[$row['type']]['name'];
                    $row['icon'] = DeployHelper::$deploy_config[$row['type']]['icon'];
                }
            } else {
                if (!empty($row['type']) && isset(CertHelper::$cert_config[$row['type']])) {
                    $row['typename'] = CertHelper::$cert_config[$row['type']]['name'];
                    $row['icon'] = CertHelper::$cert_config[$row['type']]['icon'];
                }
            }
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
                return json(['code' => -1, 'msg' => $title . '已存在']);
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
                return json(['code' => 0, 'msg' => '添加' . $title . '成功！']);
            } catch (Exception $e) {
                Db::rollback();
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'edit') {
            $id = input('post.id/d');
            $row = Db::name('cert_account')->where('id', $id)->find();
            if (!$row) return json(['code' => -1, 'msg' => $title . '不存在']);
            $type = input('post.type');
            $name = input('post.name', null, 'trim');
            $config = input('post.config', null, 'trim');
            $remark = input('post.remark', null, 'trim');
            if ($type == 'local') $name = '复制到本机';
            if (empty($name) || empty($config)) return json(['code' => -1, 'msg' => '必填参数不能为空']);
            if (Db::name('cert_account')->where('type', $type)->where('config', $config)->where('id', '<>', $id)->find()) {
                return json(['code' => -1, 'msg' => $title . '已存在']);
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
                return json(['code' => 0, 'msg' => '修改' . $title . '成功！']);
            } catch (Exception $e) {
                Db::rollback();
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'del') {
            $id = input('post.id/d');
            if ($deploy == 0) {
                $dcount = DB::name('cert_order')->where('aid', $id)->count();
                if ($dcount > 0) return json(['code' => -1, 'msg' => '该' . $title . '下存在证书订单，无法删除']);
            } else {
                $dcount = DB::name('cert_deploy')->where('aid', $id)->count();
                if ($dcount > 0) return json(['code' => -1, 'msg' => '该' . $title . '下存在自动部署任务，无法删除']);
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
            if (empty($account)) return $this->alert('error', $title . '不存在');
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
        if ($deploy == 0) {
            $mod = CertHelper::getModel($id);
            if ($mod) {
                try {
                    $ext = $mod->register();
                    if (is_array($ext)) {
                        Db::name('cert_account')->where('id', $id)->update(['ext' => json_encode($ext)]);
                    }
                    return true;
                } catch (Exception $e) {
                    throw new Exception('验证SSL证书账户失败，' . $e->getMessage());
                }
            } else {
                throw new Exception('SSL证书申请模块' . $type . '不存在');
            }
        } else {
            $mod = DeployHelper::getModel($id);
            if ($mod) {
                try {
                    $mod->check();
                    return true;
                } catch (Exception $e) {
                    throw new Exception('验证自动部署账户失败，' . $e->getMessage());
                }
            } else {
                throw new Exception('SSL证书申请模块' . $type . '不存在');
            }
        }
    }

    public function certorder()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $types = [];
        foreach (CertHelper::$cert_config as $key => $value) {
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
        $aid = input('post.aid', null, 'trim');
        $type = input('post.type', null, 'trim');
        $status = input('post.status', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('cert_order')->alias('A')->leftJoin('cert_account B', 'A.aid = B.id');
        if (!empty($id)) {
            $select->where('A.id', $id);
        } elseif (!empty($domain)) {
            $oids = Db::name('cert_domain')->where('domain', 'like', '%' . $domain . '%')->column('oid');
            $select->whereIn('A.id', $oids);
        }
        if (!empty($aid)) {
            $select->where('A.aid', $aid);
        }
        if (!empty($type)) {
            $select->where('B.type', $type);
        }
        if (!isNullOrEmpty($status)) {
            if ($status == '5') {
                $select->where('A.status', '<', 0);
            } elseif ($status == '6') {
                $select->where('A.expiretime', '<', date('Y-m-d H:i:s', time() + 86400 * 7))->where('A.expiretime', '>=', date('Y-m-d H:i:s'));
            } elseif ($status == '7') {
                $select->where('A.expiretime', '<', date('Y-m-d H:i:s'));
            } else {
                $select->where('A.status', $status);
            }
        }
        $total = $select->count();
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark')->order('id', 'desc')->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            if (!empty($row['type']) && isset(CertHelper::$cert_config[$row['type']])) {
                $row['typename'] = CertHelper::$cert_config[$row['type']]['name'];
                $row['icon'] = CertHelper::$cert_config[$row['type']]['icon'];
            } else {
                $row['typename'] = null;
            }
            $row['domains'] = Db::name('cert_domain')->where('oid', $row['id'])->order('sort', 'ASC')->column('domain');
            $row['end_day'] = $row['expiretime'] ? ceil((strtotime($row['expiretime']) - time()) / 86400) : null;
            if ($row['error']) $row['error'] = htmlspecialchars(str_replace("'", "\\'", $row['error']));
            $list[] = $row;
        }

        return json(['total' => $total, 'rows' => $list]);
    }

    public function order_info()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $id = input('post.id/d');
        $row = Db::name('cert_order')->where('id', $id)->find();
        if (!$row) return json(['code' => -1, 'msg' => '证书订单不存在']);
        $pfx = CertHelper::getPfx($row['fullchain'], $row['privatekey']);
        $row['pfx'] = base64_encode($pfx);
        return json(['code' => 0, 'data' => ['id' => $row['id'], 'crt' => $row['fullchain'], 'key' => $row['privatekey'], 'pfx' => $row['pfx'], 'issuetime' => $row['issuetime'], 'expiretime' => $row['expiretime'], 'domains' => Db::name('cert_domain')->where('oid', $row['id'])->order('sort', 'ASC')->column('domain')]]);
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
            $aid = input('post.aid/d');

            if ($aid == -1) {
                $fullchain = input('post.fullchain', null, 'trim');
                $privatekey = input('post.privatekey', null, 'trim');
                $certInfo = $this->parse_cert_key($fullchain, $privatekey);
                if ($certInfo['code'] == -1) return json($certInfo);
                $domains = $certInfo['domains'];

                $order_ids = Db::name('cert_order')->where('issuetime', $certInfo['issuetime'])->column('id');
                if (!empty($order_ids)) {
                    foreach ($order_ids as $order_id) {
                        $domains2 = Db::name('cert_domain')->where('oid', $order_id)->column('domain');
                        if (arrays_are_equal($domains2, $domains)) {
                            return json(['code' => -1, 'msg' => '该证书已存在，无需重复添加']);
                        }
                    }
                }

                $order = [
                    'aid' => 0,
                    'keytype' => $certInfo['keytype'],
                    'keysize' => $certInfo['keysize'],
                    'addtime' => date('Y-m-d H:i:s'),
                    'updatetime' => date('Y-m-d H:i:s'),
                    'issuetime' => $certInfo['issuetime'],
                    'expiretime' => $certInfo['expiretime'],
                    'issuer' => $certInfo['issuer'],
                    'status' => 3,
                    'isauto' => 1,
                    'fullchain' => $fullchain,
                    'privatekey' => $privatekey,
                ];
            } else {
                $order = [
                    'aid' => $aid,
                    'keytype' => input('post.keytype'),
                    'keysize' => input('post.keysize'),
                    'addtime' => date('Y-m-d H:i:s'),
                    'issuer' => '',
                    'status' => 0,
                    'isauto' => 1,
                ];
                $domains = input('post.domains', [], 'trim');
                $domains = array_map('trim', $domains);
                $domains = array_filter($domains, function ($v) {
                    return !empty($v);
                });
                $domains = array_unique($domains);
                if (empty($domains)) return json(['code' => -1, 'msg' => '绑定域名不能为空']);
                $res = $this->check_order($order, $domains);
                if (is_array($res)) return json($res);
            }
            if (empty($order['keytype']) || empty($order['keysize'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);

            Db::startTrans();
            $id = Db::name('cert_order')->insertGetId($order);
            $domainList = [];
            $i = 1;
            foreach ($domains as $domain) {
                $domainList[] = [
                    'oid' => $id,
                    'domain' => convertDomainToAscii($domain),
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

            $aid = input('post.aid/d');
            if ($aid == -1) {
                $fullchain = input('post.fullchain', null, 'trim');
                $privatekey = input('post.privatekey', null, 'trim');
                $certInfo = $this->parse_cert_key($fullchain, $privatekey);
                if ($certInfo['code'] == -1) return json($certInfo);
                $domains = $certInfo['domains'];

                $order = [
                    'aid' => 0,
                    'keytype' => $certInfo['keytype'],
                    'keysize' => $certInfo['keysize'],
                    'updatetime' => date('Y-m-d H:i:s'),
                    'issuetime' => $certInfo['issuetime'],
                    'expiretime' => $certInfo['expiretime'],
                    'issuer' => $certInfo['issuer'],
                    'status' => 3,
                    'issend' => 0,
                    'fullchain' => $fullchain,
                    'privatekey' => $privatekey,
                ];
            } else {
                $domains = input('post.domains', [], 'trim');
                $order = [
                    'aid' => $aid,
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
                $res = $this->check_order($order, $domains);
                if (is_array($res)) return json($res);
            }
            if (empty($order['keytype']) || empty($order['keysize'])) return json(['code' => -1, 'msg' => '必填参数不能为空']);

            Db::startTrans();
            Db::name('cert_order')->where('id', $id)->update($order);
            Db::name('cert_domain')->where('oid', $id)->delete();
            $domainList = [];
            $i = 1;
            foreach ($domains as $domain) {
                $domainList[] = [
                    'oid' => $id,
                    'domain' => convertDomainToAscii($domain),
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
            try {
                (new CertOrderService($id))->cancel();
            } catch (Exception $e) {
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
            try {
                $service = new CertOrderService($id);
                $service->cancel();
                $service->reset();
                return json(['code' => 0]);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'revoke') {
            $id = input('post.id/d');
            try {
                $service = new CertOrderService($id);
                $service->revoke();
                return json(['code' => 0]);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'show_log') {
            $processid = input('post.processid');
            $file = app()->getRuntimePath() . 'log/' . $processid . '.log';
            if (!file_exists($file)) return json(['code' => -1, 'msg' => '日志文件不存在']);
            return json(['code' => 0, 'data' => file_get_contents($file), 'time' => filemtime($file)]);
        } elseif ($action == 'operation') {
            $ids = input('post.ids');
            $success = 0;
            foreach ($ids as $id) {
                if (input('post.act') == 'delete') {
                    $dcount = DB::name('cert_deploy')->where('oid', $id)->count();
                    if ($dcount > 0) continue;
                    try {
                        (new CertOrderService($id))->cancel();
                    } catch (Exception $e) {
                    }
                    Db::name('cert_order')->where('id', $id)->delete();
                    Db::name('cert_domain')->where('oid', $id)->delete();
                    $success++;
                } elseif (input('post.act') == 'reset') {
                    try {
                        $service = new CertOrderService($id);
                        $service->cancel();
                        $service->reset();
                        $success++;
                    } catch (Exception $e) {
                    }
                } elseif (input('post.act') == 'open' || input('post.act') == 'close') {
                    $isauto = input('post.act') == 'open' ? 1 : 0;
                    Db::name('cert_order')->where('id', $id)->update(['isauto' => $isauto]);
                    $success++;
                }
            }
            return json(['code' => 0, 'msg' => '成功操作' . $success . '个证书订单']);
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
        if (count($domains) > $max_domains) {
            if (!(count($domains) == 2 && $max_domains == 1 && ltrim($domains[0], 'www.') == ltrim($domains[1], 'www.'))) {
                return ['code' => -1, 'msg' => '域名数量不能超过' . $max_domains . '个'];
            }
        }

        foreach ($domains as $domain) {
            if (!$wildcard && strpos($domain, '*') !== false) return ['code' => -1, 'msg' => '该证书账户类型不支持泛域名'];
            $mainDomain = getMainDomain($domain);
            $drow = Db::name('domain')->where('name', $mainDomain)->find();
            if (!$drow) {
                if (substr($domain, 0, 2) == '*.') $domain = substr($domain, 2);
                if (!$cname || !Db::name('cert_cname')->where('domain', $domain)->where('status', 1)->find()) {
                    return ['code' => -1, 'msg' => '域名' . $domain . '未在本系统添加'];
                }
            }
        }
        return true;
    }

    private function parse_cert_key($fullchain, $privatekey)
    {
        if (!openssl_x509_read($fullchain)) return ['code' => -1, 'msg' => '证书内容填写错误'];
        if (!openssl_get_privatekey($privatekey)) return ['code' => -1, 'msg' => '私钥内容填写错误'];
        if (!openssl_x509_check_private_key($fullchain, $privatekey)) return ['code' => -1, 'msg' => 'SSL证书与私钥不匹配'];
        $certInfo = openssl_x509_parse($fullchain, true);
        if (!$certInfo || !isset($certInfo['extensions']['subjectAltName'])) return ['code' => -1, 'msg' => '证书内容解析失败'];

        $pubKey = openssl_pkey_get_public($fullchain);
        if (!$pubKey) return ['code' => -1, 'msg' => '证书公钥解析失败'];
        $keyDetails = openssl_pkey_get_details($pubKey);
        $keytype = null;
        $keysize = 0;
        switch ($keyDetails['type']) {
            case OPENSSL_KEYTYPE_RSA:
                $keytype = 'RSA';
                $keysize = $keyDetails['bits'];
                break;
            case OPENSSL_KEYTYPE_EC:
                $keytype = 'ECC';
                $keysize = $keyDetails['bits'];
                break;
            case OPENSSL_KEYTYPE_DSA:
                $keytype = 'DSA';
                $keysize = $keyDetails['bits'];
                break;
            default:
                $keytype = 'Unknown';
        }

        $domains = [];
        $subjectAltName = explode(',', $certInfo['extensions']['subjectAltName']);
        foreach ($subjectAltName as $domain) {
            $domain = trim($domain);
            if (strpos($domain, 'DNS:') === 0) $domain = substr($domain, 4);
            if (!empty($domain)) {
                $domains[] = $domain;
            }
        }
        $domains = array_unique($domains);
        if (empty($domains)) return ['code' => -1, 'msg' => '证书绑定域名不能为空'];
        $issuetime = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
        $expiretime = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
        $issuer = $certInfo['issuer']['CN'];
        return [
            'code' => 0,
            'keytype' => $keytype,
            'keysize' => $keysize,
            'issuetime' => $issuetime,
            'expiretime' => $expiretime,
            'issuer' => $issuer,
            'domains' => $domains,
        ];
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
        try {
            $service = new CertOrderService($id);
            if ($reset == 1) {
                $service->reset();
            }
            $retcode = $service->process(true);
            if ($retcode == 3) {
                return json(['code' => 0, 'msg' => '证书已签发成功！']);
            } elseif ($retcode == 1) {
                return json(['code' => 0, 'msg' => '添加DNS记录成功！请等待DNS生效后点击验证']);
            }
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'trace' => $e->getTrace()]);
        }
    }

    public function order_form()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $action = input('param.action');

        $order = null;
        if ($action == 'edit') {
            $id = input('get.id/d');
            $order = Db::name('cert_order')->where('id', $id)->fieldRaw('id,aid,keytype,keysize,status,fullchain,privatekey')->find();
            if (empty($order)) return $this->alert('error', '证书订单不存在');
            $order['domains'] = Db::name('cert_domain')->where('oid', $order['id'])->order('sort', 'ASC')->column('domain');
            if ($order['aid'] == 0) $order['aid'] = -1;
        }

        $accounts = [];
        foreach (Db::name('cert_account')->where('deploy', 0)->select() as $row) {
            if (empty($row['type']) || !isset(CertHelper::$cert_config[$row['type']])) continue;
            $accounts[$row['id']] = ['name' => $row['id'] . '_' . CertHelper::$cert_config[$row['type']]['name'], 'type' => $row['type']];
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
        foreach (DeployHelper::$deploy_config as $key => $value) {
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
        $aid = input('post.aid', null, 'trim');
        $type = input('post.type', null, 'trim');
        $status = input('post.status', null, 'trim');
        $remark = input('post.remark', null, 'trim');
        $offset = input('post.offset/d');
        $limit = input('post.limit/d');

        $select = Db::name('cert_deploy')->alias('A')->leftJoin('cert_account B', 'A.aid = B.id')->leftJoin('cert_order C', 'A.oid = C.id')->leftJoin('cert_account D', 'C.aid = D.id');
        if (!empty($oid)) {
            $select->where('A.oid', $oid);
        } elseif (!empty($domain)) {
            $oids = Db::name('cert_domain')->where('domain', 'like', '%' . $domain . '%')->column('oid');
            $select->whereIn('oid', $oids);
        }
        if (!empty($aid)) {
            $select->where('A.aid', $aid);
        }
        if (!empty($type)) {
            $select->where('B.type', $type);
        }
        if (!isNullOrEmpty($status)) {
            $select->where('A.status', $status);
        }
        if (!empty($remark)) {
            $select->where('A.remark', $remark);
        }
        $total = $select->count();
        $rows = $select->fieldRaw('A.*,B.type,B.remark aremark,B.name aname,D.type certtype,D.id certaid')->order('id', 'desc')->limit($offset, $limit)->select();

        $list = [];
        foreach ($rows as $row) {
            if (!empty($row['type']) && isset(DeployHelper::$deploy_config[$row['type']])) {
                $row['typename'] = DeployHelper::$deploy_config[$row['type']]['name'];
                $row['icon'] = DeployHelper::$deploy_config[$row['type']]['icon'];
            }
            if (!empty($row['certtype']) && isset(CertHelper::$cert_config[$row['certtype']])) {
                $row['certtypename'] = CertHelper::$cert_config[$row['certtype']]['name'];
            } else {
                $row['certtypename'] = '手动续期';
            }
            $row['domains'] = Db::name('cert_domain')->where('oid', $row['oid'])->order('sort', 'ASC')->column('domain');
            if ($row['error']) $row['error'] = htmlspecialchars(str_replace("'", "\\'", $row['error']));
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
            try {
                $service = new CertDeployService($id);
                $service->reset();
                return json(['code' => 0]);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        } elseif ($action == 'show_log') {
            $processid = input('post.processid');
            $file = app()->getRuntimePath() . 'log/' . $processid . '.log';
            if (!file_exists($file)) return json(['code' => -1, 'msg' => '日志文件不存在']);
            return json(['code' => 0, 'data' => file_get_contents($file), 'time' => filemtime($file)]);
        } elseif ($action == 'operation') {
            $ids = input('post.ids');
            $success = 0;
            $certid = 0;
            if (input('post.action') == 'cert') {
                $certid = input('post.certid/d');
                $cert = Db::name('cert_order')->where('id', $certid)->find();
                if (!$cert) return json(['code' => -1, 'msg' => '证书订单不存在']);
            }
            foreach ($ids as $id) {
                if (input('post.act') == 'delete') {
                    Db::name('cert_deploy')->where('id', $id)->delete();
                    $success++;
                } elseif (input('post.act') == 'reset') {
                    try {
                        $service = new CertDeployService($id);
                        $service->reset();
                        $success++;
                    } catch (Exception $e) {
                    }
                } elseif (input('post.act') == 'open' || input('post.act') == 'close') {
                    $active = input('post.act') == 'open' ? 1 : 0;
                    Db::name('cert_deploy')->where('id', $id)->update(['active' => $active]);
                    $success++;
                } elseif (input('post.act') == 'cert') {
                    Db::name('cert_deploy')->where('id', $id)->update(['oid' => $certid]);
                    $success++;
                }
            }
            return json(['code' => 0, 'msg' => '成功操作' . $success . '个任务']);
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
        try {
            $service = new CertDeployService($id);
            if ($reset == 1) {
                $service->reset();
            }
            $service->process(true);
            return json(['code' => 0, 'msg' => 'SSL证书部署任务执行成功！']);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'trace' => $e->getTrace()]);
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
            if (empty($row['type']) || !isset(DeployHelper::$deploy_config[$row['type']])) continue;
            $accounts[$row['id']] = ['name' => $row['id'] . '_' . DeployHelper::$deploy_config[$row['type']]['name'], 'type' => $row['type']];
            if (!empty($row['remark'])) {
                $accounts[$row['id']]['name'] .= '（' . $row['remark'] . '）';
            }
        }
        View::assign('accounts', $accounts);

        $orders = [];
        foreach (Db::name('cert_order')->alias('A')->leftJoin('cert_account B', 'A.aid = B.id')->where('status', '<>', 4)->fieldRaw('A.id,A.aid,B.type,B.remark aremark')->order('id', 'desc')->select() as $row) {
            $domains = Db::name('cert_domain')->where('oid', $row['id'])->order('sort', 'ASC')->column('domain');
            $domainstr = count($domains) > 2 ? implode('、', array_slice($domains, 0, 2)) . '等' . count($domains) . '个域名' : implode('、', $domains);
            if ($row['aid'] == 0) {
                $name = $row['id'] . '_' . $domainstr . '（手动续期）';
            } else {
                $name = $row['id'] . '_' . $domainstr . '（' . CertHelper::$cert_config[$row['type']]['name'] . '）';
            }
            $orders[$row['id']] = ['name' => $name];
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

        $select = Db::name('cert_cname')->alias('A')->leftJoin('domain B', 'A.did = B.id');
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
                return json(['code' => -1, 'msg' => '域名' . $data['domain'] . '已存在']);
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
            if (!$result || !in_array($record, $result)) {
                $result = \app\utils\DnsQueryUtils::query_dns_doh($domain, 'CNAME');
                if (!$result || !in_array($record, $result)) {
                    $status = 0;
                }
            }
            if ($status != $row['status']) {
                Db::name('cert_cname')->where('id', $id)->update(['status' => $status]);
            }
            return json(['code' => 0, 'status' => $status]);
        }
    }

    public function certset()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }
}
