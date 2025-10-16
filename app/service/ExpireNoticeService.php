<?php

namespace app\service;

use Exception;
use think\facade\Db;
use app\utils\MsgNotice;

/**
 * 域名到期提醒
 */
class ExpireNoticeService
{

    public function updateDomainDate($id, $domain)
    {
        try {
            [$regTime, $expireTime] = getDomainDate($domain);
            Db::name('domain')->where('id', $id)->update(['regtime' => $regTime, 'expiretime' => $expireTime, 'checktime' => date('Y-m-d H:i:s'), 'checkstatus' => 1]);
            return ['code' => 0, 'regTime' => $regTime, 'expireTime' => $expireTime, 'msg' => 'Success'];
        } catch (Exception $e) {
            Db::name('domain')->where('id', $id)->update(['checktime' => date('Y-m-d H:i:s'), 'checkstatus' => 2]);
            return ['code' => -1, 'msg' => $e->getMessage()];
        }
    }

    public function task()
    {
        echo '开始执行域名到期提醒任务...' . PHP_EOL;
        config_set('domain_expire_time', date("Y-m-d H:i:s"));
        $count = $this->refreshDomainList();
        if ($count > 0) return;

        $days = config_get('expire_noticedays');
        $max_day = 30;
        if (!empty($days)) {
            $days = explode(',', $days);
            $days = array_map('intval', $days);
            $max_day = max($days) + 1;
        }
        $count = $this->refreshExpiringDomainList($max_day);
        if ($count > 0) return;

        if (!empty($days) && (config_get('expire_notice_mail') == '1' || config_get('expire_notice_wxtpl') == '1' || config_get('expire_notice_tgbot') == '1' || config_get('expire_notice_webhook') == '1') && date('H') >= 9) {
            $this->noticeExpiringDomainList($max_day, $days);
        }
    }

    private function refreshDomainList()
    {
        $domainList = Db::name('domain')->field('id,name')->where('expiretime', null)->where('checkstatus', 0)->select();
        $count = 0;
        foreach ($domainList as $domain) {
            $res = $this->updateDomainDate($domain['id'], $domain['name']);
            if ($res['code'] == 0) {
                echo '域名: ' . $domain['name'] . ' 注册时间: ' . $res['regTime'] . ' 到期时间: ' . $res['expireTime'] . PHP_EOL;
            } else {
                echo '域名: ' . $domain['name'] . ' 更新失败，' . $res['msg'] . PHP_EOL;
            }
            $count++;
            if ($count >= 5) break;
            sleep(1);
        }
        return $count;
    }

    private function refreshExpiringDomainList($max_day)
    {
        $domainList = Db::name('domain')->field('id,name')->whereRaw('expiretime>=(NOW() - INTERVAL 5 DAY) AND expiretime<=(NOW() + INTERVAL ' . $max_day . ' DAY) AND checktime<=(NOW() - INTERVAL 1 DAY)')->select();
        $count = 0;
        foreach ($domainList as $domain) {
            $res = $this->updateDomainDate($domain['id'], $domain['name']);
            if ($res['code'] == 0) {
                echo '域名: ' . $domain['name'] . ' 注册时间: ' . $res['regTime'] . ' 到期时间: ' . $res['expireTime'] . PHP_EOL;
            } else {
                echo '域名: ' . $domain['name'] . ' 更新失败，' . $res['msg'] . PHP_EOL;
            }
            $count++;
            if ($count >= 5) break;
            sleep(1);
        }
        return $count;
    }

    private function noticeExpiringDomainList($max_day, $days)
    {
        $domainList = Db::name('domain')->field('id,name,expiretime')->whereRaw('expiretime>=NOW() AND expiretime<=(NOW() + INTERVAL ' . $max_day . ' DAY) AND is_notice=1 AND (noticetime IS NULL OR noticetime<=(NOW() - INTERVAL 20 HOUR))')->order('expiretime', 'asc')->select();
        $noticeList = [];
        foreach ($domainList as $domain) {
            $expireDay = intval((strtotime($domain['expiretime']) - time()) / 86400);
            if (in_array($expireDay, $days)) {
                $noticeList[$expireDay][] = ['id' => $domain['id'], 'name' => $domain['name'], 'expiretime' => $domain['expiretime']];
            }
        }
        if (!empty($noticeList)) {
            foreach ($noticeList as $day => $list) {
                $ids = array_column($list, 'id');
                Db::name('domain')->whereIn('id', $ids)->update(['noticetime' => date('Y-m-d H:i:s')]);
                MsgNotice::expire_notice_send($day, $list);
                echo '域名到期提醒: ' . $day . '天内到期的' . count($ids) . '个域名已发送' . PHP_EOL;
            }
        }
    }
}
