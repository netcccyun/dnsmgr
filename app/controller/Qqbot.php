<?php

namespace app\controller;

use app\BaseController;
use Exception;
use think\facade\Cache;
use think\facade\Log;

class Qqbot extends BaseController
{
    public function webhook()
    {
        $body = $this->request->getContent();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return response('bad request', 400);
        }

        $appId = trim(config_get('qqbot_appid', ''));
        $appSecret = trim(config_get('qqbot_appsecret', ''));
        if ($appId === '' || $appSecret === '') {
            return response('not configured', 400);
        }

        $headerAppId = $this->request->header('x-bot-appid', '');
        if ($headerAppId !== '' && $headerAppId !== $appId) {
            return response('appid mismatch', 403);
        }

        try {
            $bot = new \app\lib\QqBot($appId, $appSecret);
            $op = (int) ($payload['op'] ?? 0);
            if ($op === 13) {
                $data = is_array($payload['d'] ?? null) ? $payload['d'] : [];
                return json($bot->signValidation((string) ($data['event_ts'] ?? ''), (string) ($data['plain_token'] ?? '')));
            }

            $timestamp = $this->request->header('x-signature-timestamp', '');
            $signature = $this->request->header('x-signature-ed25519', '');
            if (!$bot->verifySignature($timestamp, $signature, $body)) {
                return response('invalid signature', 401);
            }

            if (strtoupper((string) ($payload['t'] ?? '')) === 'C2C_MESSAGE_CREATE') {
                $event = is_array($payload['d'] ?? null) ? $payload['d'] : [];
                $openid = $event['author']['user_openid'] ?? '';
                $old = config_get('qqbot_openid', '');
                if ($openid !== '' && $old !== $openid) {
                    config_set('qqbot_openid', $openid);
                    Cache::delete('configs');
                    try {
                        $bot->sendMarkdown(
                            $openid,
                            "# 绑定成功\n\n您已成功绑定QQ机器人消息通知，聚合DNS管理系统通知将通过本机器人发送。",
                            (string) ($event['id'] ?? '')
                        );
                    } catch (\Throwable $e) {
                        Log::error('[qqbotWebhook] bind reply failed: ' . $e->getMessage());
                    }
                }
            }
            return response('', 200);
        } catch (Exception $e) {
            Log::error('[QqBot] ' . $e->getMessage());
            return response('error', 500);
        }
    }
}
