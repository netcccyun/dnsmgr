<?php

namespace app\lib\oauth;

class OAuthTokenData
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresIn = null,
        public readonly ?string $idToken = null,
        public readonly array $raw = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['access_token'] ?? ''),
            isset($data['refresh_token']) ? (string)$data['refresh_token'] : null,
            isset($data['expires_in']) ? (int)$data['expires_in'] : null,
            isset($data['id_token']) ? (string)$data['id_token'] : null,
            $data
        );
    }

    public function toLegacyArray(): array
    {
        $data = $this->raw;
        $data['access_token'] = $this->accessToken;
        if ($this->refreshToken !== null) {
            $data['refresh_token'] = $this->refreshToken;
        }
        if ($this->expiresIn !== null) {
            $data['expires_in'] = $this->expiresIn;
        }
        if ($this->idToken !== null) {
            $data['id_token'] = $this->idToken;
        }
        return $data;
    }
}
