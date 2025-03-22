<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\utils\MsgNotice;

class CertTaskService
{

    public function execute()
    {
        $this->execute_deploy();
        $this->execute_order();
        config_set('certtask_time', date("Y-m-d H:i:s"));
        echo 'done'.PHP_EOL;
    }

    private function execute_order()
    {
        $days = config_get('cert_renewdays', 7);
        $list = Db::name('cert_order')->field('id,status,issend')->whereRaw('isauto=1 AND status NOT IN (3,4) AND (retrytime IS NULL OR retrytime<NOW()) OR status=3 AND expiretime<:expiretime', ['expiretime' => date('Y-m-d H:i:s', time() + $days * 86400)])->select();
        //print_r($list);exit;
        $failcount = 0;
        foreach ($list as $row) {
            try {
                $service = new CertOrderService($row['id']);
                if ($row['status'] == 3) {
                    $service->reset();
                }
                $retcode = $service->process();
                if ($retcode == 3) {
                    echo 'ID:'.$row['id'].' 证书已签发成功！'.PHP_EOL;
                    if($row['issend'] == 0) MsgNotice::cert_order_send($row['id'], true);
                } elseif ($retcode == 1) {
                    echo 'ID:'.$row['id'].' 添加DNS记录成功！'.PHP_EOL;
                }
                break;
            } catch (Exception $e) {
                echo 'ID:'.$row['id'].' '.$e->getMessage().PHP_EOL;
                if ($e->getCode() == 102) {
                    break;
                } elseif ($e->getCode() == 103) {
                    if($row['issend'] == 0) MsgNotice::cert_order_send($row['id'], false);
                } else {
                    $failcount++;
                }
            }
            if ($failcount >= 3) break;
            sleep(1);
        }
    }

    private function execute_deploy()
    {
        $start = config_get('deploy_hour_start', 0);
        $end = config_get('deploy_hour_end', 23);
        $hour = date('H');
        if($start <= $end){
            if($hour < $start || $hour > $end){
                echo '不在部署任务运行时间范围内'.PHP_EOL; return;
            }
        }else{
            if($hour < $start && $hour > $end){
                echo '不在部署任务运行时间范围内'.PHP_EOL; return;
            }
        }

        $list = Db::name('cert_deploy')->field('id,status,issend')->whereRaw('active=1 AND status IN (0,-1) AND (retrytime IS NULL OR retrytime<NOW())')->select();
        //print_r($list);exit;
        $count = 0;
        foreach ($list as $row) {
            try {
                $service = new CertDeployService($row['id']);
                $service->process();
                echo 'ID:'.$row['id'].' 部署任务执行成功！'.PHP_EOL;
                if($row['issend'] == 0) MsgNotice::cert_deploy_send($row['id'], true);
                $count++;
            } catch (Exception $e) {
                echo 'ID:'.$row['id'].' '.$e->getMessage().PHP_EOL;
                if ($e->getCode() == 102) {
                    break;
                } elseif ($e->getCode() == 103) {
                    if($row['issend'] == 0) MsgNotice::cert_deploy_send($row['id'], false);
                } else {
                    $count++;
                }
            }
            if ($count >= 3) break;
            sleep(1);
        }
    }
}
