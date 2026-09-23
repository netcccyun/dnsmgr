<?php

namespace app\lib;

use think\facade\Cache;
use think\facade\Log;

/**
 * QQ 机器人（开放平台 API v2）
 * 用于 Webhook 验签/回调校验、单聊绑定与 Markdown 消息发送
 */
class QqBot
{
    private const TOKEN_URL = 'https://api.bot.qq.com/app/getAppAccessToken';
    private const API_BASE = 'https://api.bot.qq.com';

    private string $appId;
    private string $appSecret;

    public function __construct(string $appId, string $appSecret)
    {
        $this->appId = trim($appId);
        $this->appSecret = trim($appSecret);
        if ($this->appId === '' || $this->appSecret === '') {
            throw new \Exception('未配置QQ机器人AppID或AppSecret');
        }
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            throw new \Exception('当前PHP环境不支持Ed25519，无法使用QQ机器人');
        }
    }

    /**
     * 将站内通知模板转为 QQ Markdown
     */
    public static function toMarkdown(string $title, string $content): string
    {
        $content = str_replace(['<br/>', '<b>', '</b>'], ["\n", "**", "**"], $content);
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = trim($content);
        return '# ' . $title . "\n\n" . $content;
    }

    /**
     * 回调地址验证（op=13）：对 event_ts + plain_token 签名
     */
    public function signValidation(string $eventTs, string $plainToken): array
    {
        if ($eventTs === '' || $plainToken === '') {
            throw new \Exception('回调校验参数不完整');
        }
        $signature = $this->sign($eventTs . $plainToken);
        return [
            'plain_token' => $plainToken,
            'signature' => $signature,
        ];
    }

    /**
     * 校验 HTTP 回调签名
     */
    public function verifySignature(string $timestamp, string $signatureHex, string $body): bool
    {
        $signatureHex = strtolower(trim($signatureHex));
        if ($timestamp === '' || $signatureHex === '') {
            return false;
        }
        $signature = @hex2bin($signatureHex);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        if ((ord($signature[63]) & 224) !== 0) {
            return false;
        }
        $publicKey = sodium_crypto_sign_publickey($this->getKeyPair());
        return sodium_crypto_sign_verify_detached($signature, $timestamp . $body, $publicKey);
    }

    /**
     * 发送 Markdown 单聊消息
     */
    public function sendMarkdown(string $userOpenid, string $markdown, ?string $msgId = null): bool
    {
        $userOpenid = trim($userOpenid);
        if ($userOpenid === '' || $markdown === '') {
            throw new \Exception('QQ机器人消息参数不完整');
        }
        $post = [
            'msg_type' => 2,
            'markdown' => [
                'content' => $markdown,
            ],
        ];
        if ($msgId !== null && $msgId !== '') {
            $post['msg_id'] = $msgId;
            $post['msg_seq'] = 1;
        }
        $result = $this->apiRequest('POST', '/v2/users/' . rawurlencode($userOpenid) . '/messages', $post);
        if (!empty($result['id'])) {
            return true;
        }
        $message = (string) ($result['message'] ?? $result['msg'] ?? '发送失败');
        throw new \Exception('QQ机器人发送失败：' . $message);
    }

    /**
     * 获取 access_token（带缓存）
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'qqbot_access_token_' . md5($this->appId . ':' . $this->appSecret);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->httpRequest(self::TOKEN_URL, [
            'appId' => $this->appId,
            'clientSecret' => $this->appSecret,
        ]);
        $arr = json_decode($response, true);
        if (!is_array($arr)) {
            throw new \Exception('获取QQ机器人凭证失败，响应解析失败');
        }
        if (empty($arr['access_token'])) {
            $message = (string) ($arr['message'] ?? '未知错误');
            $code = $arr['code'] ?? '';
            throw new \Exception('获取QQ机器人凭证失败' . ($code !== '' ? '[' . $code . ']' : '') . '：' . $message);
        }

        $token = (string) $arr['access_token'];
        $ttl = (int) ($arr['expires_in'] ?? 7200);
        $ttl = max(60, $ttl - 120);
        Cache::set($cacheKey, $token, $ttl);
        return $token;
    }

    private function apiRequest(string $method, string $path, array $body): array
    {
        $token = $this->getAccessToken();
        $url = self::API_BASE . $path;
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'QQBot ' . $token,
        ];
        $post = strtoupper($method) === 'GET' ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = $this->httpRequest($url, $post, $headers);
        $arr = json_decode($response, true);
        if (!is_array($arr)) {
            Log::error('[QqBot] invalid response: ' . $response);
            throw new \Exception('QQ机器人接口响应解析失败');
        }
        if (isset($arr['code']) && (int) $arr['code'] !== 0 && empty($arr['id'])) {
            throw new \Exception('QQ机器人接口错误[' . $arr['code'] . ']：' . ($arr['message'] ?? '未知错误'));
        }
        return $arr;
    }

    private function httpRequest(string $url, $post = null, array $headers = []): string
    {
        if (is_array($post)) {
            $post = json_encode($post, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/json';
            }
        }
        $response = get_curl($url, $post, 0, 0, 0, 0, $headers);
        if ($response === false || $response === '' || $response === true) {
            throw new \Exception('QQ机器人接口请求失败');
        }
        return (string) $response;
    }

    private function sign(string $message): string
    {
        $secretKey = sodium_crypto_sign_secretkey($this->getKeyPair());
        return bin2hex(sodium_crypto_sign_detached($message, $secretKey));
    }

    private function getKeyPair(): string
    {
        return sodium_crypto_sign_seed_keypair($this->secretToSeed($this->appSecret));
    }

    /**
     * 按官方文档将 Bot Secret repeat 后截取为 32 字节 seed
     */
    private function secretToSeed(string $secret): string
    {
        $seed = $secret;
        while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            $seed .= $seed;
        }
        return substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
    }
}
