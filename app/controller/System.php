<?php

namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;
use app\service\OptimizeService;
use app\service\CertTaskService;
use app\service\ExpireNoticeService;
use app\service\ScheduleService;

class System extends BaseController
{
    public function set()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $params = input('post.');
        if (isset($params['mail_type']) && isset($params['mail_name2']) && $params['mail_type'] > 0) {
            $params['mail_name'] = $params['mail_name2'];
            unset($params['mail_name2']);
        }
        foreach ($params as $key => $value) {
            if (empty($key)) {
                continue;
            }
            config_set($key, $value);
        }
        Cache::delete('configs');
        return json(['code' => 0, 'msg' => 'succ']);
    }

    public function loginset()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }

    public function noticeset()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }

    public function proxyset()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        return View::fetch();
    }

    public function mailtest()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $mail_name = config_get('mail_recv') ? config_get('mail_recv') : config_get('mail_name');
        if (empty($mail_name)) return json(['code' => -1, 'msg' => '您还未设置邮箱！']);
        $result = \app\utils\MsgNotice::send_mail($mail_name, '邮件发送测试。', '这是一封测试邮件！<br/><br/>来自：' . $this->request->root(true));
        if ($result === true) {
            return json(['code' => 0, 'msg' => '邮件发送成功！']);
        } else {
            return json(['code' => -1, 'msg' => '邮件发送失败！' . $result]);
        }
    }

    public function tgbottest()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $tgbot_token = config_get('tgbot_token');
        $tgbot_chatid = config_get('tgbot_chatid');
        if (empty($tgbot_token) || empty($tgbot_chatid)) return json(['code' => -1, 'msg' => '请先保存设置']);
        $content = "<strong>消息发送测试</strong>\n\n这是一封测试消息！\n\n来自：" . $this->request->root(true);
        $result = \app\utils\MsgNotice::send_telegram_bot($content);
        if ($result === true) {
            return json(['code' => 0, 'msg' => '消息发送成功！']);
        } else {
            return json(['code' => -1, 'msg' => '消息发送失败！' . $result]);
        }
    }

    public function webhooktest()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $webhook_url = config_get('webhook_url');
        if (empty($webhook_url)) return json(['code' => -1, 'msg' => '请先保存设置']);
        $content = "这是一封测试消息！\n来自：" . $this->request->root(true);
        $result = \app\utils\MsgNotice::send_webhook('消息发送测试', $content);
        if ($result === true) {
            return json(['code' => 0, 'msg' => '消息发送成功！']);
        } else {
            return json(['code' => -1, 'msg' => '消息发送失败！' . $result]);
        }
    }

    public function proxytest()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $proxy_server = input('post.proxy_server', '', 'trim');
        $proxy_port = input('post.proxy_port/d', 0);
        $proxy_user = input('post.proxy_user', '', 'trim');
        $proxy_pwd = input('post.proxy_pwd', '', 'trim');
        $proxy_type = input('post.proxy_type', 'http', 'trim');
        
        try {
            check_proxy('https://dl.amh.sh/ip.htm', $proxy_server, $proxy_port, $proxy_type, $proxy_user, $proxy_pwd);
        } catch (Exception $e) {
            try {
                check_proxy('https://myip.ipip.net/', $proxy_server, $proxy_port, $proxy_type, $proxy_user, $proxy_pwd);
            } catch (Exception $e) {
                return json(['code' => -1, 'msg' => $e->getMessage()]);
            }
        }
        return json(['code' => 0]);
    }

    public function cronset()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if (config_get('cron_key') === null) {
            config_set('cron_key', random(10));
            Cache::delete('configs');
        }
        View::assign('is_user_www', isset($_SERVER['USER']) && $_SERVER['USER'] == 'www');
        View::assign('siteurl', request()->root(true));
        return View::fetch();
    }

    public function cron()
    {
        if (function_exists("set_time_limit")) {
            @set_time_limit(0);
        }
        if (function_exists("ignore_user_abort")) {
            @ignore_user_abort(true);
        }
        if (isset($_SERVER['HTTP_USER_AGENT']) && str_contains($_SERVER['HTTP_USER_AGENT'], 'Baiduspider')) exit;
        $key = input('get.key', '');
        $cron_key = config_get('cron_key');
        if (config_get('cron_type', '0') != '1' || empty($cron_key)) exit('未开启当前方式');
        if ($key != $cron_key) exit('访问密钥错误');

        (new ScheduleService())->execute();
        $res = (new OptimizeService())->execute();
        if (!$res) {
            (new CertTaskService())->execute();
            (new ExpireNoticeService())->task();
        }
        echo 'success!';
    }
}