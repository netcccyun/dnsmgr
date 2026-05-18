<?php

namespace app\lib\oauth;

use app\lib\OAuth;
use Exception;

class OAuth2 extends OAuth
{
    private function getFieldMap(): array
    {
        $defaults = ['openid' => 'id,sub,openid,user_id', 'nickname' => 'name,nickname,preferred_username,login,username', 'email' => 'email', 'avatar' => 'avatar,avatar_url,picture'];
        if (!empty($this->config['userinfo_fields'])) {
            $custom = json_decode($this->config['userinfo_fields'], true);
            if (is_array($custom)) {
                return array_merge($defaults, $custom);
            }
        }
        return $defaults;
    }

    public function getAuthorizeUrl(string $state): string
    {
        if (!(new OAuthUrlValidator())->isSafeHttpsUrl($this->config['oauth_authorize_url'])) {
            throw new Exception('自定义OAuth2授权端点URL不安全');
        }
        $verifier = $this->createPkceVerifier($state);
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => $this->config['scopes'] ?? '',
            'code_challenge' => $this->createPkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
        return $this->appendQuery($this->config['oauth_authorize_url'], $params);
    }

    public function getAccessToken(string $code, string $state = ''): OAuthTokenData
    {
        if (!(new OAuthUrlValidator())->isSafeHttpsUrl($this->config['oauth_token_url'])) {
            throw new Exception('自定义OAuth2 Token端点URL不安全');
        }
        $data = $this->jsonPost($this->config['oauth_token_url'], [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $this->consumePkceVerifier($state),
        ], [
            'Accept' => 'application/json',
        ]);

        if (empty($data['access_token'])) {
            throw new Exception('获取access_token失败');
        }
        return OAuthTokenData::fromArray($data);
    }

    public function getUserInfo(string $accessToken): OAuthUserInfo
    {
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];

        if (!(new OAuthUrlValidator())->isSafeHttpsUrl($this->config['oauth_userinfo_url'])) {
            throw new Exception('自定义OAuth2用户信息端点URL不安全');
        }
        $data = $this->jsonGet($this->config['oauth_userinfo_url'], $headers);
        $map = $this->getFieldMap();
        $openid = (string)($this->getByPath($data, $map['openid']) ?? '');
        if ($openid === '') {
            throw new Exception('自定义OAuth2获取用户标识失败：请在字段映射中把 openid 映射到用户信息接口返回的唯一ID字段，例如 {"openid":"sub"} 或 {"openid":"id"}');
        }

        return new OAuthUserInfo(
            $openid,
            (string)($this->getByPath($data, $map['nickname']) ?? ''),
            (string)($this->getByPath($data, $map['email']) ?? ''),
            (string)($this->getByPath($data, $map['avatar']) ?? ''),
            null,
            null,
            $data
        );
    }

    private function appendQuery(string $url, string $query): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    private function getByPath(array $data, string $path): mixed
    {
        foreach (explode(',', $path) as $candidatePath) {
            $candidatePath = trim($candidatePath);
            if ($candidatePath === '') {
                continue;
            }
            $value = $data;
            foreach (explode('.', $candidatePath) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    continue 2;
                }
                $value = $value[$segment];
            }
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }
}
