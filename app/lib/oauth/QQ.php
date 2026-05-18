<?php

namespace app\lib\oauth;

use app\lib\OAuth;
use Exception;

class QQ extends OAuth
{
    private function getExtConfig(): array
    {
        if (!empty($this->config['ext_config'])) {
            $ext = json_decode($this->config['ext_config'], true);
            if (is_array($ext)) {
                return $ext;
            }
        }
        return [];
    }

    public function getAuthorizeUrl(string $state): string
    {
        $scopes = $this->config['scopes'] ?: 'get_user_info';
        $ext = $this->getExtConfig();
        if (!empty($ext['unionid'])) {
            $scopes .= ' unionid';
        }
        $verifier = $this->createPkceVerifier($state);
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => $scopes,
            'code_challenge' => $this->createPkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
        return 'https://graph.qq.com/oauth2.0/authorize?' . $params;
    }

    public function getAccessToken(string $code, string $state = ''): OAuthTokenData
    {
        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'fmt' => 'json',
            'code_verifier' => $this->consumePkceVerifier($state),
        ];
        $resp = $this->httpGet('https://graph.qq.com/oauth2.0/token?' . http_build_query($params));

        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            parse_str($resp['body'], $data);
        }
        if (!empty($data['access_token'])) {
            return OAuthTokenData::fromArray($data);
        }
        if (!empty($data['error'])) {
            throw new Exception('QQ登录授权失败：' . ($data['error_description'] ?? $data['msg'] ?? $data['error']));
        }

        throw new Exception('QQ登录授权失败：未获取到访问令牌，请重新发起登录');
    }

    public function getUserInfo(string $accessToken): OAuthUserInfo
    {
        $ext = $this->getExtConfig();
        $useUnionid = !empty($ext['unionid']);

        $meUrl = 'https://graph.qq.com/oauth2.0/me?access_token=' . urlencode($accessToken) . '&fmt=json';
        if ($useUnionid) {
            $meUrl .= '&unionid=1';
        }
        $openidResp = $this->httpGet($meUrl);
        $openidData = json_decode($openidResp['body'], true);
        if (empty($openidData['openid']) && preg_match('/callback\s*\(\s*(.*?)\s*\)\s*;?\s*$/s', $openidResp['body'], $matches)) {
            $openidData = json_decode($matches[1], true);
        }
        if (!is_array($openidData)) {
            throw new Exception('QQ登录返回数据异常，请重新发起登录');
        }
        if (!empty($openidData['error'])) {
            throw new Exception('QQ登录授权失败：' . ($openidData['error_description'] ?? $openidData['msg'] ?? $openidData['error']));
        }
        if (empty($openidData['openid'])) {
            throw new Exception('QQ登录授权已过期，请重新发起登录');
        }
        $openid = (string)$openidData['openid'];
        $unionid = isset($openidData['unionid']) ? (string)$openidData['unionid'] : null;

        $params = http_build_query([
            'access_token' => $accessToken,
            'oauth_consumer_key' => $this->config['client_id'],
            'openid' => $openid,
        ]);
        $userData = $this->jsonGet('https://graph.qq.com/user/get_user_info?' . $params);

        if (!isset($userData['ret'])) {
            throw new Exception('QQ登录获取用户信息失败：返回数据缺少状态码');
        }
        if ((int)$userData['ret'] !== 0) {
            throw new Exception('QQ登录获取用户信息失败：' . ($userData['msg'] ?? '请确认应用已开通 get_user_info 权限'));
        }

        return new OAuthUserInfo(
            $openid,
            $userData['nickname'] ?? '',
            '',
            ($userData['figureurl_qq_2'] ?? '') ?: ($userData['figureurl_qq_1'] ?? ''),
            $unionid,
            null,
            ['openid' => $openidData, 'user' => $userData]
        );
    }
}
