<?php

namespace app\lib\oauth;

use Exception;

class OAuthProviderFactory
{
    public function create(array $providerConfig): OAuthProviderInterface
    {
        return match ($providerConfig['type'] ?? '') {
            'qq' => new QQ($providerConfig),
            'github' => new GitHub($providerConfig),
            'oauth2' => new OAuth2($providerConfig),
            'oidc' => new OIDC($providerConfig),
            'cccyun' => new Cccyun($providerConfig),
            default => throw new Exception('不支持的OAuth类型: ' . ($providerConfig['type'] ?? '')),
        };
    }
}
