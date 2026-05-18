<?php

namespace app\lib;

use app\lib\oauth\OAuthHttpClient;
use app\lib\oauth\OAuthProviderFactory;
use app\lib\oauth\OAuthProviderInterface;
use app\lib\oauth\OAuthTokenData;
use app\lib\oauth\OAuthUserInfo;

abstract class OAuth implements OAuthProviderInterface
{
    protected array $config;
    protected string $redirectUri;
    protected OAuthHttpClient $httpClient;
    protected string $state = '';

    public function __construct(array $config, ?OAuthHttpClient $httpClient = null)
    {
        $this->config = $config;
        $this->httpClient = $httpClient ?: new OAuthHttpClient();
    }

    public function setRedirectUri(string $uri): void
    {
        $this->redirectUri = $uri;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    protected function createPkceVerifier(string $state): string
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        session('oauth_pkce_' . $state, $verifier);
        return $verifier;
    }

    protected function consumePkceVerifier(string $state): string
    {
        if ($state === '') {
            throw new \Exception('OAuth授权状态缺失，请重新发起第三方登录');
        }
        $key = 'oauth_pkce_' . $state;
        $verifier = (string)session($key);
        session($key, null);
        if ($verifier === '') {
            throw new \Exception('OAuth PKCE验证信息已失效，请重新发起第三方登录');
        }
        return $verifier;
    }

    protected function createPkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    public static function create(array $providerConfig): OAuthProviderInterface
    {
        return (new OAuthProviderFactory())->create($providerConfig);
    }

    abstract public function getAuthorizeUrl(string $state): string;

    abstract public function getAccessToken(string $code, string $state = ''): OAuthTokenData;

    abstract public function getUserInfo(string $accessToken): OAuthUserInfo;

    protected function httpGet(string $url, array $headers = []): array
    {
        return $this->httpClient->get($url, $headers);
    }

    protected function httpPost(string $url, array|string $data, array $headers = []): array
    {
        return $this->httpClient->post($url, $data, $headers);
    }

    protected function jsonGet(string $url, array $headers = []): array
    {
        return $this->httpClient->getJson($url, $headers);
    }

    protected function jsonPost(string $url, array|string $data, array $headers = []): array
    {
        return $this->httpClient->postJson($url, $data, $headers);
    }
}
