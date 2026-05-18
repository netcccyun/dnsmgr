<?php

namespace app\lib\oauth;

use app\lib\OAuth;
use Exception;

class GitHub extends OAuth
{
    public function getAuthorizeUrl(string $state): string
    {
        $verifier = $this->createPkceVerifier($state);
        $params = http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => $this->config['scopes'] ?: 'read:user user:email',
            'code_challenge' => $this->createPkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
        return 'https://github.com/login/oauth/authorize?' . $params;
    }

    public function getAccessToken(string $code, string $state = ''): OAuthTokenData
    {
        $data = $this->jsonPost('https://github.com/login/oauth/access_token', [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $this->consumePkceVerifier($state),
        ], [
            'Accept' => 'application/json',
        ]);

        if (empty($data['access_token'])) {
            throw new Exception('GitHub获取access_token失败');
        }
        return OAuthTokenData::fromArray($data);
    }

    public function getUserInfo(string $accessToken): OAuthUserInfo
    {
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];

        $userData = $this->jsonGet('https://api.github.com/user', $headers);
        if (empty($userData['id'])) {
            throw new Exception('GitHub获取用户信息失败');
        }

        $email = $userData['email'] ?? '';
        if (empty($email)) {
            $emails = $this->jsonGet('https://api.github.com/user/emails', $headers);
            foreach ($emails as $item) {
                if (!empty($item['primary']) && !empty($item['verified'])) {
                    $email = $item['email'];
                    break;
                }
            }
            if (empty($email)) {
                foreach ($emails as $item) {
                    if (!empty($item['verified'])) {
                        $email = $item['email'];
                        break;
                    }
                }
            }
        }

        return new OAuthUserInfo(
            (string)$userData['id'],
            $userData['login'] ?? '',
            $email,
            $userData['avatar_url'] ?? '',
            null,
            null,
            $userData
        );
    }
}
