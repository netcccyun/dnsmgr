<?php

namespace app\service\oauth;

use app\lib\OAuth;
use app\lib\oauth\OAuthTokenData;
use app\lib\oauth\OAuthUserInfo;
use Exception;
use think\facade\Db;

class OAuthAuthService
{
    public function __construct(
        private ?OAuthSessionStore $sessionStore = null,
        private ?OAuthTokenStore $tokenStore = null
    ) {
        $this->sessionStore = $sessionStore ?: new OAuthSessionStore();
        $this->tokenStore = $tokenStore ?: new OAuthTokenStore();
    }

    public function buildLoginRedirect(int $providerId, string $callbackUrl): string
    {
        $provider = $this->getEnabledProvider($providerId);
        $oauth = OAuth::create($provider);
        $oauth->setRedirectUri($callbackUrl);
        $state = bin2hex(random_bytes(16));
        $this->sessionStore->startLogin($state, $providerId, $callbackUrl);
        return $oauth->getAuthorizeUrl($state);
    }

    public function buildBindRedirect(int $providerId, int $userId, string $callbackUrl): string
    {
        $provider = $this->getEnabledProvider($providerId);
        $oauth = OAuth::create($provider);
        $oauth->setRedirectUri($callbackUrl);
        $state = bin2hex(random_bytes(16));
        $this->sessionStore->startBind($state, $providerId, $userId, $callbackUrl);
        return $oauth->getAuthorizeUrl($state);
    }

    public function handleCallback(int $providerId, string $code, string $state, string $callbackUrl, ?int $currentUserId = null): array
    {
        [$flow, $bindUserId] = $this->sessionStore->consumeFlow($state, $providerId, $callbackUrl);
        if ($flow === 'bind' && ($currentUserId === null || $currentUserId !== $bindUserId)) {
            throw new Exception('绑定登录态已变化，请重新登录后再绑定');
        }
        $provider = $this->getEnabledProvider($providerId);
        $oauth = OAuth::create($provider);
        $oauth->setRedirectUri($callbackUrl);
        $tokenData = $oauth->getAccessToken($code, $state);
        $userInfo = $this->normalizeUserInfo($oauth->getUserInfo($tokenData->accessToken));
        if ($userInfo->openid === '') {
            throw new Exception('获取用户标识失败');
        }

        if ($flow === 'bind') {
            $bindResult = $this->bindUser($provider, $userInfo, $tokenData, $bindUserId);
            if ($bindResult !== null) {
                return $bindResult;
            }
            return ['type' => 'alert', 'level' => 'success', 'msg' => '绑定' . $provider['name'] . '账号成功！', 'url' => '/user/center'];
        }

        $user = $this->loginByOAuth($provider, $userInfo, $tokenData);
        return [
            'type' => 'login',
            'user' => $user,
            'provider' => $provider,
            'userInfo' => $userInfo,
            'tokenData' => $tokenData,
            'requiresTotp' => !empty($user['totp_open']) && !empty($user['totp_secret']),
        ];
    }

    public function updateLoginBinding(array $provider, OAuthUserInfo $userInfo, OAuthTokenData $tokenData): void
    {
        $binding = Db::name('user_oauth')
            ->where('provider_id', $provider['id'])
            ->where('openid', $userInfo->openid)
            ->find();
        if (!$binding && !empty($userInfo->rawOpenid)) {
            $binding = Db::name('user_oauth')
                ->where('provider_id', $provider['id'])
                ->where('openid', $userInfo->rawOpenid)
                ->find();
        }
        if (!$binding) {
            throw new Exception('该第三方账号尚未绑定本站账号，请先使用可用登录方式进入用户中心绑定；如无法登录，请联系管理员');
        }
        $updateData = ['openid' => $userInfo->openid] + $this->buildBindingData($userInfo, $tokenData, false);
        Db::name('user_oauth')->where('id', $binding['id'])->update($updateData);
    }

    public function unbind(int $userId, int $providerId): void
    {
        Db::transaction(function () use ($userId, $providerId) {
            $record = Db::name('user_oauth')
                ->where('user_id', $userId)
                ->where('provider_id', $providerId)
                ->lock(true)
                ->find();
            if (!$record) {
                throw new Exception('未绑定该提供商');
            }

            if (config_get('oauth_disable_password', '0') == '1') {
                $remaining = Db::name('user_oauth')->alias('uo')
                    ->join('oauth_provider op', 'op.id = uo.provider_id')
                    ->where('uo.user_id', $userId)
                    ->where('uo.id', '<>', $record['id'])
                    ->where('op.enabled', 1)
                    ->lock(true)
                    ->count();
                if ($remaining == 0) {
                    throw new Exception('密码登录已禁用，不能解绑最后一个可用的第三方登录');
                }
            }

            Db::name('user_oauth')->where('id', $record['id'])->delete();
            Db::name('log')->insert([
                'uid' => $userId,
                'action' => '解绑OAuth',
                'data' => 'ProviderID:' . $providerId . ', OpenIDHash:' . $this->hashOAuthIdentifier((string)$record['openid']),
                'addtime' => date('Y-m-d H:i:s'),
            ]);
        });
    }

    private function getEnabledProvider(int $providerId): array
    {
        $provider = Db::name('oauth_provider')->where('id', $providerId)->where('enabled', 1)->find();
        if (!$provider) {
            throw new Exception('OAuth提供商不存在或已禁用');
        }
        return $provider;
    }

    private function normalizeUserInfo(OAuthUserInfo $userInfo): OAuthUserInfo
    {
        if (!empty($userInfo->unionid)) {
            return $userInfo->withOpenid($userInfo->unionid, $userInfo->openid);
        }
        return $userInfo;
    }

    private function loginByOAuth(array $provider, OAuthUserInfo $userInfo, OAuthTokenData $tokenData): array
    {
        $binding = Db::name('user_oauth')
            ->where('provider_id', $provider['id'])
            ->where('openid', $userInfo->openid)
            ->find();
        if (!$binding && !empty($userInfo->rawOpenid)) {
            $binding = Db::name('user_oauth')
                ->where('provider_id', $provider['id'])
                ->where('openid', $userInfo->rawOpenid)
                ->find();
        }

        if (!$binding) {
            throw new Exception('该第三方账号尚未绑定本站账号，请先使用可用登录方式进入用户中心绑定；如无法登录，请联系管理员');
        }

        $user = Db::name('user')->where('id', $binding['user_id'])->find();
        if (!$user || $user['status'] != 1) {
            throw new Exception('绑定的账号不存在或已被封禁');
        }

        return $user;
    }

    private function bindUser(array $provider, OAuthUserInfo $userInfo, OAuthTokenData $tokenData, int $userId): ?array
    {
        if ($userId <= 0) {
            throw new Exception('绑定用户信息已失效，请重新登录');
        }
        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user || $user['status'] != 1) {
            throw new Exception('绑定用户不存在或已被禁用，请重新登录');
        }
        $openids = [$userInfo->openid];
        if (!empty($userInfo->rawOpenid)) {
            $openids[] = $userInfo->rawOpenid;
        }
        $existing = Db::name('user_oauth')
            ->where('provider_id', $provider['id'])
            ->whereIn('openid', array_values(array_unique($openids)))
            ->find();

        if ($existing) {
            if ($existing['user_id'] == $userId) {
                return ['type' => 'alert', 'level' => 'warning', 'msg' => '该账号已绑定此' . $provider['name'] . '账号，无需重复绑定', 'url' => '/user/center'];
            }
            throw new Exception('该' . $provider['name'] . '账号已被其他用户绑定');
        }

        $userProviderBinding = Db::name('user_oauth')
            ->where('user_id', $userId)
            ->where('provider_id', $provider['id'])
            ->find();
        if ($userProviderBinding) {
            throw new Exception('该用户已绑定此' . $provider['name'] . '提供商，请先解绑后再绑定');
        }

        $insertData = [
            'user_id' => $userId,
            'provider_id' => $provider['id'],
            'openid' => $userInfo->openid,
        ] + $this->buildBindingData($userInfo, $tokenData, true);

        try {
            Db::name('user_oauth')->insert($insertData);
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'uk_user_provider') !== false) {
                throw new Exception('该用户已绑定此' . $provider['name'] . '提供商，请先解绑后再绑定');
            }
            if (stripos($e->getMessage(), 'uk_provider_openid') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
                throw new Exception('该' . $provider['name'] . '账号已被其他用户绑定');
            }
            throw $e;
        }

        Db::name('log')->insert([
            'uid' => $userId,
            'action' => '绑定OAuth',
            'data' => 'Provider:' . $provider['name'] . ', OpenIDHash:' . $this->hashOAuthIdentifier($userInfo->openid),
            'addtime' => date('Y-m-d H:i:s'),
        ]);

        return null;
    }

    private function hashOAuthIdentifier(string $identifier): string
    {
        return substr(hash('sha256', $identifier), 0, 16);
    }

    private function buildBindingData(OAuthUserInfo $userInfo, OAuthTokenData $tokenData, bool $includeAddtime): array
    {
        $now = date('Y-m-d H:i:s');
        $data = [
            'nickname' => $userInfo->nickname,
            'email' => $userInfo->email,
            'avatar' => $userInfo->avatar,
            'lasttime' => $now,
        ] + $this->tokenStore->buildSaveData($tokenData);
        if ($includeAddtime) {
            $data['addtime'] = $now;
        }
        return $data;
    }
}
