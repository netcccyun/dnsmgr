<?php

namespace app\lib\oauth;

interface OAuthProviderInterface
{
    public function setRedirectUri(string $uri): void;

    public function getAuthorizeUrl(string $state): string;

    public function getAccessToken(string $code, string $state = ''): OAuthTokenData;

    public function getUserInfo(string $accessToken): OAuthUserInfo;
}
