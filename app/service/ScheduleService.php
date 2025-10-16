<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\lib\DnsHelper;

/**
 * 域名定时切换解析
 */
class ScheduleService
{

    public function execute()
    {
        $list = Db::name('sctask')->where('nexttime', '>', 0)->where('nexttime', '<=', time())->where('active', 1)->select();
        if (count($list) == 0) {
            return false;
        }
        echo '开始执行定时切换解析任务，共获取到' . count($list) . '个待执行任务' . "\n";
        foreach ($list as $row) {
            try {
                $this->execute_one($row);
                echo '定时切换任务' . $row['id'] . '执行成功' . "\n";
            } catch (Exception $e) {
                echo '定时切换任务' . $row['id'] . '执行失败,' . $e->getMessage() . "\n";
            }
        }
        config_set('schedule_time', date("Y-m-d H:i:s"));
        return true;
    }

    public function execute_one($row)
    {
        $drow = Db::name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type,B.ak,B.sk,B.ext')->find();
        if (!$drow) throw new Exception('域名不存在');

        Db::name('sctask')->where('id', $row['id'])->update(['updatetime' => time()]);

        $domain = $row['rr'] . '.' . $drow['name'];
        $dns = DnsHelper::getModel2($drow);
        if ($row['switchtype'] == 1) {
            $res = $dns->setDomainRecordStatus($row['recordid'], '1');
            if ($res) {
                $this->add_log($domain, '启用解析', '定时启用解析成功');
            } else {
                $this->add_log($domain, '启用解析失败', $dns->getError());
            }
        } elseif ($row['switchtype'] == 2) {
            $res = $dns->setDomainRecordStatus($row['recordid'], '0');
            if ($res) {
                $this->add_log($domain, '暂停解析', '定时暂停解析成功');
            } else {
                $this->add_log($domain, '暂停解析失败', $dns->getError());
            }
        } elseif ($row['switchtype'] == 3) {
            $res = $dns->deleteDomainRecord($row['recordid']);
            if ($res) {
                $this->add_log($domain, '删除解析', '定时删除解析成功');
            } else {
                $this->add_log($domain, '删除解析失败', $dns->getError());
            }
        } else {
            $recordinfo = json_decode($row['recordinfo'], true);
            if ($drow['type'] == 'cloudflare' && !isNullOrEmpty($row['line'])) {
                $recordinfo['Line'] = $row['line'];
            }
            $res = $dns->updateDomainRecord($row['recordid'], $row['rr'], getDnsType($row['value']), $row['value'], $recordinfo['Line'], $recordinfo['TTL']);
            if ($res) {
                $this->add_log($domain, '修改解析', $row['rr'].' ['.getDnsType($row['value']).'] '.$row['value'].' (线路:'.$recordinfo['Line'].' TTL:'.$recordinfo['TTL'].')');
            } else {
                $this->add_log($domain, '修改解析失败', $dns->getError());
            }
        }

        $this->update_nexttime($row);
    }

    public function update_nexttime($row)
    {
        if ($row['type'] == 1) {
            if ($row['cycle'] == 2) {
                $date = intval($row['switchdate']);
                $nexttime = strtotime(date('Y-m-') . $date . ' ' . $row['switchtime'] . ':00');
                if ($nexttime <= time()) {
                    $nexttime = strtotime("+1 month", $nexttime);
                }
            } elseif ($row['cycle'] == 1) {
                $weekday = intval($row['switchdate']); // 0-6, 0=周日
                $nexttime = strtotime("last Sunday +{$weekday} days {$row['switchtime']}:00");
                if ($nexttime <= time()) {
                    $nexttime = strtotime("+1 week", $nexttime);
                    if ($nexttime <= time()) {
                        $nexttime = strtotime("+1 week", $nexttime);
                    }
                }
            } else {
                $nexttime = strtotime(date('Y-m-d') . ' ' . $row['switchtime'] . ':00');
                if ($nexttime <= time()) {
                    $nexttime = strtotime("+1 day", $nexttime);
                }
            }
        } else {
            $nexttime = strtotime($row['switchtime'] . ':00');
            if ($nexttime <= time()) {
                $nexttime = 0;
            }
        }
        Db::name('sctask')->where('id', $row['id'])->update(['nexttime' => $nexttime]);
    }

    private function add_log($domain, $action, $data)
    {
        if (strlen($data) > 500) $data = substr($data, 0, 500);
        Db::name('log')->insert(['uid' => 0, 'domain' => $domain, 'action' => $action, 'data' => $data, 'addtime' => date("Y-m-d H:i:s")]);
    }
}
