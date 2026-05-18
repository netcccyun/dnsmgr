<?php

namespace app\service\oauth;

use Exception;

class OAuthSessionStore
{
    private const STATE_TTL = 600;

    public function startLogin(string $state, int $providerId, string $callbackUrl): void
    {
        $this->saveContext($state, [
            'state' => $state,
            'flow' => 'login',
            'user_id' => 0,
            'provider_id' => $providerId,
            'callback_url' => $callbackUrl,
            'expires_at' => time() + self::STATE_TTL,
        ]);
    }

    public function startBind(string $state, int $providerId, int $userId, string $callbackUrl): void
    {
        $this->saveContext($state, [
            'state' => $state,
            'flow' => 'bind',
            'user_id' => $userId,
            'provider_id' => $providerId,
            'callback_url' => $callbackUrl,
            'expires_at' => time() + self::STATE_TTL,
        ]);
    }

    public function consumeFlow(string $state, int $providerId, string $callbackUrl): array
    {
        $contexts = session('oauth_state_contexts') ?: [];
        if (!is_array($contexts)) {
            $contexts = [];
        }
        $context = $contexts[$state] ?? null;
        if (!is_array($context)
            || empty($context['state'])
            || !hash_equals((string)$context['state'], $state)
            || (int)($context['provider_id'] ?? 0) !== $providerId
            || (string)($context['callback_url'] ?? '') !== $callbackUrl
            || (int)($context['expires_at'] ?? 0) < time()
        ) {
            throw new Exception('授权状态已失效或已处理，请重新发起第三方登录');
        }
        unset($contexts[$state]);
        session('oauth_state_contexts', $contexts);
        session('oauth_state_context', null);
        return [(string)(($context['flow'] ?? '') ?: 'login'), (int)($context['user_id'] ?? 0)];
    }

    private function saveContext(string $state, array $context): void
    {
        $contexts = session('oauth_state_contexts') ?: [];
        if (!is_array($contexts)) {
            $contexts = [];
        }
        $now = time();
        foreach ($contexts as $key => $storedContext) {
            if (!is_array($storedContext) || (int)($storedContext['expires_at'] ?? 0) < $now) {
                unset($contexts[$key]);
            }
        }
        $contexts[$state] = $context;
        session('oauth_state_contexts', $contexts);
        session('oauth_state_context', null);
    }
}
