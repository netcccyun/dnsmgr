<?php

namespace app\service;

use app\lib\NewDb;
use app\lib\DnsHelper;
use app\utils\CheckUtils;
use app\utils\MsgNotice;

/**
 * 容灾监控任务执行
 */
class TaskRunner
{
    private $conn;

    private function db()
    {
        if (!$this->conn) {
            $this->conn = NewDb::connect();
        }
        return $this->conn;
    }

    private function closeDb()
    {
        if ($this->conn) {
            $this->conn->close();
        }
    }

    public function execute($row)
    {
        if ($row['type'] == 3) { //条件开启解析
            $action = 0;
            $remain = $this->db()->name('dmtask')->where(['did' => $row['did'], 'rr' => $row['rr'], 'type' => 1, 'status' => 0])->count();
            if ($remain <= $row['cycle'] && $row['status'] == 0) {
                $action = 2;
                $this->db()->name('dmtask')->where('id', $row['id'])->update(['status' => 1, 'errcount' => 0, 'switchtime' => time()]);
            } elseif ($remain > $row['cycle'] && $row['status'] == 1) {
                $action = 1;
                $this->db()->name('dmtask')->where('id', $row['id'])->update(['status' => 0, 'errcount' => 0, 'switchtime' => time()]);
            }
        } else {
            if ($row['checktype'] == 2) {
                $result = CheckUtils::curl($row['checkurl'], $row['timeout'], $row['main_value'], $row['proxy'] == 1);
            } elseif ($row['checktype'] == 1) {
                $result = CheckUtils::tcp($row['main_value'], $row['checkurl'], $row['tcpport'], $row['timeout']);
            } else {
                $result = CheckUtils::ping($row['main_value'], $row['checkurl']);
            }

            $action = 0;
            if ($result['status'] && $row['status'] == 1) {
                if ($row['cycle'] <= 1 || $row['errcount'] >= $row['cycle']) {
                    $this->db()->name('dmtask')->where('id', $row['id'])->update(['status' => 0, 'errcount' => 0, 'switchtime' => time()]);
                    $action = 2;
                } else {
                    $this->db()->name('dmtask')->where('id', $row['id'])->inc('errcount')->update();
                }
            } elseif (!$result['status'] && $row['status'] == 0) {
                if ($row['cycle'] <= 1 || $row['errcount'] >= $row['cycle']) {
                    $this->db()->name('dmtask')->where('id', $row['id'])->update(['status' => 1, 'errcount' => 0, 'switchtime' => time()]);
                    $action = 1;
                } else {
                    $this->db()->name('dmtask')->where('id', $row['id'])->inc('errcount')->update();
                }
            } elseif ($row['errcount'] > 0) {
                $this->db()->name('dmtask')->where('id', $row['id'])->update(['errcount' => 0]);
            }
        }

        if ($action > 0) {
            $drow = $this->db()->name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type,B.config')->find();
            if (!$drow) {
                echo '域名不存在（ID：'.$row['did'].'）'."\n";
                $this->closeDb();
                return;
            }
            $row['domain'] = $row['rr'] . '.' . $drow['name'];
        }
        if ($action == 1) {
            if ($row['type'] == 2) {
                $dns = DnsHelper::getModel2($drow);
                $recordinfo = json_decode($row['recordinfo'], true);
                if ($drow['type'] == 'cloudflare' && $row['cdn'] == 1) {
                    $recordinfo['Line'] = '1';
                }
                $res = $dns->updateDomainRecord($row['recordid'], $row['rr'], getDnsType($row['backup_value']), $row['backup_value'], $recordinfo['Line'], $recordinfo['TTL']);
                if (!$res) {
                    $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => '修改解析失败', 'data' => $dns->getError(), 'addtime' => date("Y-m-d H:i:s")]);
                }
            } elseif ($row['type'] == 1 || $row['type'] == 3) {
                $dns = DnsHelper::getModel2($drow);
                $res = $dns->setDomainRecordStatus($row['recordid'], '0');
                if (!$res) {
                    $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => '暂停解析失败', 'data' => $dns->getError(), 'addtime' => date("Y-m-d H:i:s")]);
                }
            }
        } elseif ($action == 2) {
            if ($row['type'] == 2) {
                $dns = DnsHelper::getModel2($drow);
                $recordinfo = json_decode($row['recordinfo'], true);
                if ($drow['type'] == 'cloudflare' && $row['cdn'] == 1) {
                    $recordinfo['Line'] = '0';
                }
                $res = $dns->updateDomainRecord($row['recordid'], $row['rr'], getDnsType($row['main_value']), $row['main_value'], $recordinfo['Line'], $recordinfo['TTL']);
                if (!$res) {
                    $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => '修改解析失败', 'data' => $dns->getError(), 'addtime' => date("Y-m-d H:i:s")]);
                }
            } elseif ($row['type'] == 1 || $row['type'] == 3) {
                $dns = DnsHelper::getModel2($drow);
                $res = $dns->setDomainRecordStatus($row['recordid'], '1');
                if (!$res) {
                    $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => '启用解析失败', 'data' => $dns->getError(), 'addtime' => date("Y-m-d H:i:s")]);
                }
            }
        } else {
            $this->closeDb();
            return;
        }

        $this->db()->name('dmlog')->insert([
            'taskid' => $row['id'],
            'action' => $action,
            'errmsg' => isset($result) ? ($result['status'] ? null : $result['errmsg']) : null,
            'date' => date('Y-m-d H:i:s')
        ]);
        $this->closeDb();

        if ($row['type'] != 3) {
            MsgNotice::send($action, $row, $result);
        }
    }
}
