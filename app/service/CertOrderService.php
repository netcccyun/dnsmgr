<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\CertHelper;
use app\utils\CertDnsUtils;

/**
 * SSL证书订单处理
 */
class CertOrderService
{
    private static $retry_interval = [60, 180, 300, 600, 600];

    private $client;
    private $aid;
    private $atype;
    private $order;
    private $info;
    private $dnsList;
    private $domainList;
    private $cnameDomainList = [];

    // 订单状态：0:待提交 1:待验证 2:正在验证 3:已签发 4:已吊销 -1:购买证书失败 -2:创建订单失败 -3:添加DNS失败 -4:验证DNS失败 -5:验证订单失败 -6:订单验证未通过 -7:签发证书失败
    public function __construct($oid)
    {
        $order = Db::name('cert_order')->where('id', $oid)->find();
        if (!$order) throw new Exception('该证书订单不存在', 102);
        $this->order = $order;

        $this->aid = $order['aid'];
        $account = Db::name('cert_account')->where('id', $this->aid)->find();
        if (!$account) throw new Exception('该证书账户不存在', 102);
        $config = json_decode($account['config'], true);
        $ext = $account['ext'] ? json_decode($account['ext'], true) : null;
        $this->atype = $account['type'];
        $this->client = CertHelper::getModel2($account['type'], $config, $ext);
        if (!$this->client) throw new Exception('该证书类型不存在', 102);

        $domainList = Db::name('cert_domain')->where('oid', $oid)->order('sort', 'asc')->column('domain');
        if (!$domainList) throw new Exception('该证书订单没有绑定域名', 102);
        $this->domainList = $domainList;
        $this->info = $order['info'] ? json_decode($order['info'], true) : null;
        $this->dnsList = $order['dns'] ? json_decode($order['dns'], true) : null;
    }

    //执行证书申请
    public function process($isManual = false)
    {
        if ($this->order['status'] >= 3) return 3;
        if ($this->order['retry2'] >= 3 && !$isManual) {
            throw new Exception('已超出最大重试次数('.$this->order['error'].')', 103);
        }
        if ($this->order['status'] != 1 && $this->order['status'] != 2 && $this->order['retry'] >= 3 && !$isManual) {
            if ($this->order['status'] == -2 || $this->order['status'] == -5 || $this->order['status'] == -6 || $this->order['status'] == -7) {
                $this->cancel();
                if($this->order['status'] <= -5) $this->delDns();
                Db::name('cert_order')->where('id', $this->order['id'])->data(['status' => 0, 'retry' => 0, 'retrytime' => null, 'updatetime' => date('Y-m-d H:i:s')])->inc('retry2')->update();
                $this->order['status'] = 0;
                $this->order['retry'] = 0;
            } else {
                throw new Exception('已超出最大重试次数('.$this->order['error'].')', 103);
            }
        }

        $cname = CertHelper::$cert_config[$this->atype]['cname'];
        foreach($this->domainList as $domain){
            $mainDomain = getMainDomain($domain);
            $drow = Db::name('domain')->where('name', $mainDomain)->find();
            if (!$drow && preg_match('/^xn--/', $mainDomain)) {
                $drow = Db::name('domain')->where('name', idn_to_utf8($mainDomain))->find();
            }
            if (!$drow) {
                if (substr($domain, 0, 2) == '*.') $domain = substr($domain, 2);
                $cname_row = Db::name('cert_cname')->where('domain', $domain)->where('status', 1)->find();
                if (!$cname || !$cname_row) {
                    $errmsg = '域名'.$domain.'未在本系统添加';
                    Db::name('cert_order')->where('id', $this->order['id'])->data(['error'=>$errmsg]);
                    throw new Exception($errmsg, 103);
                } else {
                    $this->cnameDomainList[] = $cname_row['id'];
                }
            }
        }

        $this->lockOrder();
        try {
            return $this->processOrder($isManual);
        } finally {
            $this->unlockOrder();
            if (($this->order['status'] == -2 || $this->order['status'] == -5 || $this->order['status'] == -6 || $this->order['status'] == -7) && $this->order['retry'] >= 3) {
                Db::name('cert_order')->where('id', $this->order['id'])->data(['retrytime' => date('Y-m-d H:i:s', time() + 3600)])->update();
            }
        }
    }

    private function processOrder($isManual = false)
    {
        $this->client->setLogger(function ($txt) {
            $this->saveLog($txt);
        });
        // step1: 购买证书
        if ($this->order['status'] == 0 || $this->order['status'] == -1) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始购买证书');
            $this->buyCert();
        }
        // step2: 创建订单
        if ($this->order['status'] == 0 || $this->order['status'] == -2) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始创建订单');
            $this->createOrder();
        }
        // step3: 添加DNS
        if ($isManual && $this->order['status'] == -3 && CertDnsUtils::verifyDns($this->dnsList)) {
            $this->saveResult(1);
            $this->saveLog('检测到DNS记录已添加成功');
            return 1;
        }
        if ($this->order['status'] == 0 || $this->order['status'] == -3) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始添加DNS记录');
            $this->addDns();
            $this->saveLog('添加DNS记录成功，请等待生效后进行验证...');
            if (CertHelper::$cert_config[$this->atype]['cname']) {
                Db::name('cert_order')->where('id', $this->order['id'])->update(['retrytime' => date('Y-m-d H:i:s', time() + 180)]);
            }
            return 1;
        }
        // step4: 查询DNS
        if ($this->order['status'] == 1 || $this->order['status'] == -4) {
            $this->verifyDns();
        }
        // step5: 验证订单
        if ($this->order['status'] == 1 || $this->order['status'] == -5) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始验证订单');
            $this->authOrder();
        }
        // step6: 查询验证结果
        if ($this->order['status'] == 2 || $this->order['status'] == -6) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始查询验证结果');
            $this->getAuthStatus();
        }
        // step7: 签发证书
        if ($this->order['status'] == 2 || $this->order['status'] == -7) {
            $this->saveLog(date('Y-m-d H:i:s').' - 开始签发证书');
            $this->finalizeOrder();
        }
        $this->delDns();
        $this->resetRetry2();
        $this->saveLog('[Success] 证书签发成功');
        Db::name('cert_deploy')->where('oid', $this->order['id'])->data(['status' => 0, 'retry' => 0, 'retrytime' => null, 'issend' => 0])->update();
        return 3;
    }

    private function lockOrder()
    {
        Db::startTrans();
        try {
            $isLock = Db::name('cert_order')->where('id', $this->order['id'])->lock(true)->value('islock');
            if ($isLock == 1 && time() - strtotime($this->order['locktime']) < 3600) {
                throw new Exception('订单正在处理中，请稍后再试', 102);
            }
            $update = ['islock' => 1, 'locktime' => date('Y-m-d H:i:s')];
            if (empty($this->order['processid'])) $this->order['processid'] = $update['processid'] = getSid();
            Db::name('cert_order')->where('id', $this->order['id'])->update($update);
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    private function unlockOrder()
    {
        Db::name('cert_order')->where('id', $this->order['id'])->update(['islock' => 0]);
    }

    private function saveResult($status, $error = null, $retrytime = null)
    {
        $this->order['status'] = $status;
        if (mb_strlen($error) > 300) {
            $error = mb_strcut($error, 0, 300);
        }
        $update = ['status' => $status, 'error' => $error, 'updatetime' => date('Y-m-d H:i:s'), 'retrytime' => $retrytime];
        $res = Db::name('cert_order')->where('id', $this->order['id'])->data($update);
        if ($status < 0 || $retrytime) {
            $this->order['retry']++;
            $res->inc('retry');
        }
        $res->update();
        if ($error) {
            $this->saveLog('[Error] ' . $error);
        }
    }

    private function resetRetry()
    {
        if ($this->order['retry'] > 0) {
            $this->order['retry'] = 0;
            Db::name('cert_order')->where('id', $this->order['id'])->update(['retry' => 0, 'retrytime' => null]);
        }
    }

    private function resetRetry2()
    {
        if ($this->order['retry2'] > 0) {
            $this->order['retry2'] = 0;
            Db::name('cert_order')->where('id', $this->order['id'])->update(['retry2' => 0, 'retrytime' => null]);
        }
    }

    //重置订单
    public function reset()
    {
        Db::name('cert_order')->where('id', $this->order['id'])->data(['status' => 0, 'retry' => 0, 'retry2' => 0, 'retrytime' => null, 'processid' => null, 'updatetime' => date('Y-m-d H:i:s'), 'issend' => 0, 'islock' => 0])->update();
        $file_name = app()->getRuntimePath().'log/'.$this->order['processid'].'.log';
        if (file_exists($file_name)) unlink($file_name);
        $this->order['status'] = 0;
        $this->order['retry'] = 0;
        $this->order['retry2'] = 0;
        $this->order['processid'] = null;
    }

    //购买证书
    public function buyCert()
    {
        try {
            $this->client->buyCert($this->domainList, $this->info);
        } catch (Exception $e) {
            $this->saveResult(-1, $e->getMessage());
            throw $e;
        }
        if($this->info){
            Db::name('cert_order')->where('id', $this->order['id'])->update(['info' => json_encode($this->info)]);
        }
        $this->order['status'] = 0;
        $this->resetRetry();
    }

    //创建订单
    public function createOrder()
    {
        try {
            if (!empty($this->cnameDomainList)) {
                foreach($this->cnameDomainList as $cnameId){
                    $this->checkDomainCname($cnameId);
                }
            }
            try {
                $this->dnsList = $this->client->createOrder($this->domainList, $this->info, $this->order['keytype'], $this->order['keysize']);
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'KeyID header contained an invalid account URL') !== false) {
                    $ext = $this->client->register();
                    Db::name('cert_account')->where('id', $this->aid)->update(['ext' => json_encode($ext)]);
                    $this->dnsList = $this->client->createOrder($this->domainList, $this->info, $this->order['keytype'], $this->order['keysize']);
                } else {
                    throw $e;
                }
            }
        } catch (Exception $e) {
            $this->saveResult(-2, $e->getMessage());
            throw $e;
        }
        Db::name('cert_order')->where('id', $this->order['id'])->update(['info' => json_encode($this->info), 'dns' => json_encode($this->dnsList)]);

        if (!empty($this->dnsList)) {
            $dns_txt = '需验证的DNS记录如下:';
            foreach ($this->dnsList as $mainDomain => $list) {
                foreach ($list as $row) {
                    $domain = $row['name'] . '.' . $mainDomain;
                    $dns_txt .= PHP_EOL.'主机记录: '.$domain.' 类型: '.$row['type'].' 记录值: '.$row['value'];
                }
            }
            $this->saveLog($dns_txt);
        }
        $this->order['status'] = 0;
        $this->resetRetry();
    }

    //验证DNS记录
    public function verifyDns()
    {
        $verify = CertDnsUtils::verifyDns($this->dnsList);
        if (!$verify) {
            if ($this->order['retry'] >= 10) {
                $this->saveResult(-4, '未查询到DNS解析记录');
            } else {
                $this->saveLog('未查询到DNS解析记录(尝试第'.($this->order['retry']+1).'次)');
                $this->saveResult(1, null, date('Y-m-d H:i:s', time() + (array_key_exists($this->order['retry'], self::$retry_interval) ? self::$retry_interval[$this->order['retry']] : 1800)));
            }
            throw new Exception('未查询到DNS解析记录(尝试第'.($this->order['retry']).'次)，请稍后再试');
        }
        if($this->order['retry'] == 0 && time() - strtotime($this->order['updatetime']) < 10){
            throw new Exception('请等待'.(10 - (time() - strtotime($this->order['updatetime']))).'秒后再试');
        }
        $this->order['status'] = 1;
        $this->resetRetry();
    }

    //验证订单
    public function authOrder()
    {
        try {
            $this->client->authOrder($this->domainList, $this->info);
        } catch (Exception $e) {
            $this->saveResult(-5, $e->getMessage());
            throw $e;
        }
        $this->saveResult(2);
        $this->resetRetry();
    }

    //查询验证结果
    public function getAuthStatus()
    {
        try {
            $status = $this->client->getAuthStatus($this->domainList, $this->info);
        } catch (Exception $e) {
            $this->saveResult(-6, $e->getMessage());
            throw $e;
        }
        if(!$status){
            if ($this->order['retry'] >= 10) {
                $this->saveResult(-6, '订单验证未通过');
            } else {
                $this->saveLog('订单验证未通过(尝试第'.($this->order['retry']+1).'次)');
                $this->saveResult(2, null, date('Y-m-d H:i:s', time() + (array_key_exists($this->order['retry'], self::$retry_interval) ? self::$retry_interval[$this->order['retry']] : 1800)));
            }
            throw new Exception('订单验证未通过(尝试第'.($this->order['retry']).'次)，请稍后再试');
        }
        $this->order['status'] = 2;
        $this->resetRetry();
    }

    //签发证书
    public function finalizeOrder()
    {
        try {
            $result = $this->client->finalizeOrder($this->domainList, $this->info, $this->order['keytype'], $this->order['keysize']);
        } catch (Exception $e) {
            $this->saveResult(-7, $e->getMessage());
            throw $e;
        }
        $this->order['issuer'] = $result['issuer'];
        Db::name('cert_order')->where('id', $this->order['id'])->update(['fullchain' => $result['fullchain'], 'privatekey' => $result['private_key'], 'issuer' => $result['issuer'], 'issuetime' => date('Y-m-d H:i:s', $result['validFrom']), 'expiretime' => date('Y-m-d H:i:s', $result['validTo'])]);
        $this->saveResult(3);
        $this->resetRetry();
    }

    //吊销证书
    public function revoke()
    {
        $this->client->setLogger(function ($txt) {
            $this->saveLog($txt);
        });
        try {
            $this->client->revoke($this->info, $this->order['fullchain']);
        } catch (Exception $e) {
            throw $e;
        }
        $this->saveResult(4);
    }

    //取消证书订单
    public function cancel(){
        $this->client->setLogger(function ($txt) {
            $this->saveLog($txt);
        });
        if($this->order['status'] == 1 || $this->order['status'] == 2 || $this->order['status'] < -2){
            try {
                $this->client->cancel($this->info);
            } catch (Exception $e) {
            }
        }
    }

    //添加DNS记录
    public function addDns()
    {
        if (empty($this->dnsList)) {
            $this->saveResult(1);
            return;
        }
        try {
            CertDnsUtils::addDns($this->dnsList, function ($txt) {
                $this->saveLog($txt);
            }, !empty($this->cnameDomainList));
        } catch (Exception $e) {
            $this->saveResult(-3, $e->getMessage());
            throw $e;
        }
        $this->saveResult(1);
        $this->resetRetry();
    }

    //删除DNS记录
    public function delDns()
    {
        if (empty($this->dnsList)) return;
        try {
            CertDnsUtils::delDns($this->dnsList, function ($txt) {
                $this->saveLog($txt);
            }, true);
        } catch (Exception $e) {
            $this->saveLog('[Error] ' . $e->getMessage());
        }
    }

    //检查域名CNAME代理记录
    private function checkDomainCname($id)
    {
        $row = Db::name('cert_cname')->alias('A')->join('domain B', 'A.did = B.id')->where('A.id', $id)->field('A.*,B.name cnamedomain')->find();
        $domain = '_acme-challenge.' . $row['domain'];
        $record = $row['rr'] . '.' . $row['cnamedomain'];
        $result = \app\utils\DnsQueryUtils::get_dns_records($domain, 'CNAME');
        if (!$result || !in_array($record, $result)) {
            $result = \app\utils\DnsQueryUtils::query_dns_doh($domain, 'CNAME');
            if (!$result || !in_array($record, $result)) {
                if ($row['status'] == 1) {
                    Db::name('cert_cname')->where('id', $id)->update(['status' => 0]);
                }
                throw new Exception('域名' . $row['domain'] . '的CNAME代理记录未验证通过');
            }
        }
    }

    private function saveLog($txt)
    {
        if (empty($this->order['processid'])) return;
        if (!is_dir(app()->getRuntimePath() . 'log')) mkdir(app()->getRuntimePath() . 'log');
        $file_name = app()->getRuntimePath().'log/'.$this->order['processid'].'.log';
        $file_exists = file_exists($file_name);
        file_put_contents($file_name, $txt . PHP_EOL, FILE_APPEND);
        if (!$file_exists) {
            @chmod($file_name, 0777);
        }
        if(php_sapi_name() == 'cli'){
            echo $txt . PHP_EOL;
        }
    }
}
