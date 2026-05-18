<?php

namespace app\lib\oauth;

class OAuthUserInfo
{
    public function __construct(
        public readonly string $openid,
        public readonly string $nickname = '',
        public readonly string $email = '',
        public readonly string $avatar = '',
        public readonly ?string $unionid = null,
        public readonly ?string $rawOpenid = null,
        public readonly array $raw = []
    ) {}

    public function withOpenid(string $openid, ?string $rawOpenid = null): self
    {
        return new self($openid, $this->nickname, $this->email, $this->avatar, $this->unionid, $rawOpenid, $this->raw);
    }

    public function toLegacyArray(): array
    {
        return [
            'openid' => $this->openid,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'unionid' => $this->unionid ?? '',
            'raw_openid' => $this->rawOpenid ?? '',
        ];
    }
}
