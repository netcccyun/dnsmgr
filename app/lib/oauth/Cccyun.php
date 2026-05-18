<?php

namespace app\lib\oauth;

use app\lib\OAuth;
use Exception;

class Cccyun extends OAuth
{
    private const DEFAULT_BASE_URL = 'https://u.cccyun.cc/';
    public const SUPPORTED_TYPES = ['qq', 'wx', 'alipay', 'sina', 'baidu', 'huawei', 'xiaomi', 'douyin', 'bilibili', 'dingtalk'];

    private ?array $callbackData = null;

    public function getAuthorizeUrl(string $state): string
    {
        $data = $this->jsonGet($this->buildApiUrl([
            'act' => 'login',
            'appid' => (string)$this->config['client_id'],
            'appkey' => (string)$this->config['client_secret'],
            'type' => $this->getCccyunType(),
            'redirect_uri' => $this->appendQuery($this->redirectUri, ['state' => $state]),
        ]));

        if ((int)($data['code'] ?? -1) !== 0) {
            throw new Exception('彩虹聚合登录授权地址获取失败：' . (string)($data['msg'] ?? '未知错误'));
        }
        if (empty($data['url'])) {
            throw new Exception('彩虹聚合登录授权地址获取失败：服务未返回跳转地址');
        }

        return (string)$data['url'];
    }

    public function getAccessToken(string $code, string $state = ''): OAuthTokenData
    {
        $data = $this->jsonGet($this->buildApiUrl([
            'act' => 'callback',
            'appid' => (string)$this->config['client_id'],
            'appkey' => (string)$this->config['client_secret'],
            'type' => $this->getCccyunType(),
            'code' => $code,
        ]));

        $resultCode = (int)($data['code'] ?? -1);
        if ($resultCode === 2) {
            throw new Exception('彩虹聚合登录尚未完成，请重新发起第三方登录');
        }
        if ($resultCode !== 0) {
            throw new Exception('彩虹聚合登录授权失败：' . (string)($data['msg'] ?? '未知错误'));
        }
        if (empty($data['access_token'])) {
            throw new Exception('彩虹聚合登录授权失败：未获取到访问令牌');
        }
        if (empty($data['social_uid'])) {
            throw new Exception('彩虹聚合登录获取用户标识失败');
        }

        $this->callbackData = $data;
        return OAuthTokenData::fromArray($data);
    }

    public function getUserInfo(string $accessToken): OAuthUserInfo
    {
        if (!$this->callbackData) {
            throw new Exception('彩虹聚合登录用户信息已失效，请重新发起登录');
        }

        return new OAuthUserInfo(
            (string)$this->callbackData['social_uid'],
            (string)($this->callbackData['nickname'] ?? ''),
            '',
            (string)($this->callbackData['faceimg'] ?? ''),
            null,
            null,
            $this->callbackData
        );
    }

    private function getCccyunType(): string
    {
        $type = (string)($this->getExtConfig()['cccyun_type'] ?? '');
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new Exception('彩虹聚合登录配置异常：请选择有效的登录方式');
        }
        return $type;
    }

    private function getExtConfig(): array
    {
        if (!empty($this->config['ext_config'])) {
            $ext = json_decode((string)$this->config['ext_config'], true);
            if (is_array($ext)) {
                return $ext;
            }
        }
        return [];
    }

    private function buildApiUrl(array $params): string
    {
        return $this->getApiUrl() . '?' . http_build_query($params);
    }

    private function getApiUrl(): string
    {
        $baseUrl = trim((string)($this->getExtConfig()['cccyun_url'] ?? '')) ?: self::DEFAULT_BASE_URL;
        return rtrim($baseUrl, '/') . '/connect.php';
    }

    private function appendQuery(string $url, array $params): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }
}
