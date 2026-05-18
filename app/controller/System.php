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
use app\service\oauth\OAuthProviderService;

class System extends BaseController
{
    public function set()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        if (!checkRefererHost()) return json(['code' => -1, 'msg' => '非法请求']);
        $params = input('post.');
        unset($params['submit']);
        $allowedConfigKeys = [
            'vcode',
            'oauth_disable_password',
            'cert_renewdays',
            'deploy_hour_start',
            'deploy_hour_end',
            'cert_notice_mail',
            'cert_notice_wxtpl',
            'cert_notice_tgbot',
            'cert_notice_webhook',
            'cert_notice_custom_webhook',
            'proxy_server',
            'proxy_port',
            'proxy_user',
            'proxy_pwd',
            'proxy_type',
            'cron_type',
            'cron_key',
            'mail_type',
            'mail_smtp',
            'mail_port',
            'mail_name',
            'mail_name2',
            'mail_pwd',
            'mail_apiuser',
            'mail_apikey',
            'mail_recv',
            'wechat_apptoken',
            'wechat_appuid',
            'tgbot_token',
            'tgbot_chatid',
            'tgbot_proxy',
            'tgbot_url',
            'webhook_url',
            'webhook_user',
            'custom_webhook_url',
            'custom_webhook_method',
            'custom_webhook_content_type',
            'custom_webhook_headers',
            'custom_webhook_body',
            'custom_webhook_content_format',
            'optimize_ip_api',
            'optimize_ip_key',
            'optimize_ip_proxy',
            'optimize_ip_min',
            'expire_noticedays',
            'expire_notice_mail',
            'expire_notice_wxtpl',
            'expire_notice_tgbot',
            'expire_notice_webhook',
            'expire_notice_custom_webhook',
            'notice_mail',
            'notice_wxtpl',
            'notice_tgbot',
            'notice_webhook',
            'notice_custom_webhook',
        ];
        foreach (array_keys($params) as $key) {
            if (!in_array($key, $allowedConfigKeys, true)) {
                return json(['code' => -1, 'msg' => '非法配置项']);
            }
        }
        if (isset($params['mail_type']) && isset($params['mail_name2']) && $params['mail_type'] > 0) {
            $params['mail_name'] = $params['mail_name2'];
            unset($params['mail_name2']);
        }
        if (isset($params['mail_name2'])) {
            unset($params['mail_name2']);
        }
        // 禁用密码登录保护：至少有一个启用的 OAuth 提供商，且至少一个可用管理员已绑定
        if (isset($params['oauth_disable_password']) && $params['oauth_disable_password'] == '1') {
            $count = Db::name('oauth_provider')->where('enabled', 1)->count();
            if ($count == 0) {
                return json(['code' => -1, 'msg' => '至少需要启用一个OAuth提供商才能禁用密码登录']);
            }
            if (!(new OAuthProviderService())->hasAdminOauthBinding()) {
                return json(['code' => -1, 'msg' => '至少需要一个超级管理员账号绑定已启用的OAuth提供商，才能禁用密码登录']);
            }
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
        View::assign('siteurl', request()->root(true));
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

    public function customwebhooktest()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        $custom_webhook_url = config_get('custom_webhook_url');
        if (empty($custom_webhook_url)) return json(['code' => -1, 'msg' => '请先保存设置']);
        $content = "这是一封测试消息！\n来自：" . $this->request->root(true);
        $result = \app\utils\MsgNotice::send_custom_webhook('消息发送测试', $content);
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

    public function oauth_data()
    {
        if (!checkPermission(2)) return json(['total' => 0, 'rows' => []]);
        $offset = input('post.offset/d', 0);
        $limit = input('post.limit/d', 10);
        $sort = input('post.sort', 'sort');
        $order = input('post.order', 'asc');

        return json((new OAuthProviderService())->list($offset, $limit, $sort, $order));
    }

    public function oauth_op()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        if (!checkRefererHost()) return json(['code' => -1, 'msg' => '非法请求']);
        $act = input('param.act');
        $service = new OAuthProviderService();

        try {
            if ($act == 'get') {
                return json(['code' => 0, 'data' => $service->get(input('post.id/d'))]);
            } elseif ($act == 'add' || $act == 'edit') {
                $isEdit = ($act == 'edit');
                $id = $isEdit ? input('post.id/d') : 0;
                $data = [
                    'name' => input('post.name', '', 'trim'),
                    'type' => input('post.type', '', 'trim'),
                    'logo' => input('post.logo', '', 'trim'),
                    'client_id' => input('post.client_id', '', 'trim'),
                    'client_secret' => input('post.client_secret', '', 'trim'),
                    'scopes' => input('post.scopes', '', 'trim'),
                    'oauth_authorize_url' => input('post.oauth_authorize_url', '', 'trim'),
                    'oauth_token_url' => input('post.oauth_token_url', '', 'trim'),
                    'oauth_userinfo_url' => input('post.oauth_userinfo_url', '', 'trim'),
                    'oidc_issuer' => input('post.oidc_issuer', '', 'trim'),
                    'userinfo_fields' => input('post.userinfo_fields', '', 'trim'),
                    'ext_config' => input('post.ext_config', '', 'trim'),
                    'sort' => input('post.sort/d', 0),
                    'enabled' => input('post.enabled/d', 1),
                ];
                $service->save($data, $isEdit, $id);
                return json(['code' => 0, 'msg' => $isEdit ? '修改成功' : '添加成功']);
            } elseif ($act == 'del') {
                $service->delete(input('post.id/d'));
                return json(['code' => 0, 'msg' => '删除成功']);
            }
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
        return json(['code' => -3]);
    }
}
