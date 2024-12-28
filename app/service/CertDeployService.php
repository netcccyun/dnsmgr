<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\DeployHelper;

/**
 * SSL证书自动部署
 */
class CertDeployService
{
    private static $retry_interval = [60, 300, 600, 1800, 3600];

    private $client;
    private $aid;
    private $task;
    private $info;

    //任务状态：0:待处理 1:已完成 -1:处理失败
    public function __construct($tid)
    {
        $task = Db::name('cert_deploy')->where('id', $tid)->find();
        if (!$task) throw new Exception('该自动部署任务不存在', 102);
        $this->task = $task;

        $this->aid = $task['aid'];
        $this->client = DeployHelper::getModel($this->aid);
        if (!$this->client) throw new Exception('该自动部署任务类型不存在', 102);

        $this->info = $task['info'] ? json_decode($task['info'], true) : null;
    }

    public function process($isManual = false)
    {
        if ($this->task['status'] >= 1) return;
        if ($this->task['retry'] >= 6 && !$isManual) {
            throw new Exception('已超出最大重试次数('.$this->task['error'].')', 103);
        }

        $order = Db::name('cert_order')->where('id', $this->task['oid'])->find();
        if(!$order) throw new Exception('SSL证书订单不存在', 102);
        if($order['status'] == 4) throw new Exception('SSL证书订单已吊销', 102);
        if($order['status'] != 3) throw new Exception('SSL证书订单未完成签发', 102);
        if(empty($order['fullchain']) || empty($order['privatekey'])) throw new Exception('SSL证书或私钥内容不存在', 102);

        $this->lockTaskData();
        try {
            $this->deploy($order['fullchain'], $order['privatekey']);
        } finally {
            $this->unlockTaskData();
        }
    }

    //部署证书
    public function deploy($fullchain, $privatekey)
    {
        $this->client->setLogger(function ($txt) {
            $this->saveLog($txt);
        });
        $this->saveLog(date('Y-m-d H:i:s'));
        $config = json_decode($this->task['config'], true);
        $config['domainList'] = Db::name('cert_domain')->where('oid', $this->task['oid'])->order('sort', 'asc')->column('domain');
        try {
            $this->client->deploy($fullchain, $privatekey, $config, $this->info);
            $this->saveResult(1);
            $this->saveLog('[Success] 证书部署成功');
        } catch (Exception $e) {
            $this->saveResult(-1, $e->getMessage(), date('Y-m-d H:i:s', time() + (array_key_exists($this->task['retry'], self::$retry_interval) ? self::$retry_interval[$this->task['retry']] : 3600)));
            throw $e;
        } finally {
            if($this->info){
                Db::name('cert_deploy')->where('id', $this->task['id'])->update(['info' => json_encode($this->info)]);
            }
        }
    }

    //重置任务
    public function reset()
    {
        Db::name('cert_deploy')->where('id', $this->task['id'])->data(['status' => 0, 'retry' => 0, 'retrytime' => null, 'issend' => 0])->update();
        //$file_name = app()->getRuntimePath().'log/'.$this->task['processid'].'.log';
        //if (file_exists($file_name)) unlink($file_name);
        $this->task['status'] = 0;
        $this->task['retry'] = 0;
    }

    private function saveResult($status, $error = null, $retrytime = null)
    {
        $this->task['status'] = $status;
        $update = ['status' => $status, 'error' => $error, 'retrytime' => $retrytime];
        if ($status == 1){
            $update['retry'] = 0;
            $update['lasttime'] = date('Y-m-d H:i:s');
        }
        $res = Db::name('cert_deploy')->where('id', $this->task['id'])->data($update);
        if ($status < 0 || $retrytime) {
            $this->task['retry']++;
            $res->inc('retry');
        }
        $res->update();
        if ($error) {
            $this->saveLog('[Error] ' . $error);
        }
    }

    private function lockTaskData()
    {
        Db::startTrans();
        try {
            $isLock = Db::name('cert_deploy')->where('id', $this->task['id'])->lock(true)->value('islock');
            if ($isLock == 1 && time() - strtotime($this->task['locktime']) < 3600) {
                throw new Exception('部署任务处理中，请稍后再试');
            }
            $update = ['islock' => 1, 'locktime' => date('Y-m-d H:i:s')];
            if (empty($this->task['processid'])) $this->task['processid'] = $update['processid'] = getSid();
            Db::name('cert_deploy')->where('id', $this->task['id'])->update($update);
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    private function unlockTaskData()
    {
        Db::name('cert_deploy')->where('id', $this->task['id'])->update(['islock' => 0]);
    }

    private function saveLog($txt)
    {
        if (empty($this->task['processid'])) return;
        if (!is_dir(app()->getRuntimePath() . 'log')) mkdir(app()->getRuntimePath() . 'log');
        $file_name = app()->getRuntimePath().'log/'.$this->task['processid'].'.log';
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