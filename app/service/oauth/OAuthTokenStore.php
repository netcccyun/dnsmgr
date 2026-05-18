<?php

namespace app\service\oauth;

use app\lib\oauth\OAuthTokenData;

class OAuthTokenStore
{
    public function buildSaveData(OAuthTokenData $tokenData): array
    {
        $data = [
            'refresh_token' => null,
            'token_expires' => null,
        ];
        if ($tokenData->accessToken !== '') {
            $data['access_token'] = authcode($tokenData->accessToken, 'ENCODE', config_get('sys_key'));
        }
        if (!empty($tokenData->refreshToken)) {
            $data['refresh_token'] = authcode($tokenData->refreshToken, 'ENCODE', config_get('sys_key'));
        }
        if (!empty($tokenData->expiresIn)) {
            $data['token_expires'] = date('Y-m-d H:i:s', time() + $tokenData->expiresIn);
        }
        return $data;
    }
}
