<?php

namespace app\lib\oauth;

use app\lib\OAuth;
use Exception;

class OIDC extends OAuth
{
    private ?array $discovery = null;
    private array $tokenData = [];

    private function discover(): array
    {
        if ($this->discovery !== null) {
            return $this->discovery;
        }
        $issuer = rtrim((string)$this->config['oidc_issuer'], '/');
        $urlValidator = new OAuthUrlValidator();
        if (!$urlValidator->isSafeHttpsUrl($issuer)) {
            throw new Exception('OIDC Issuer URL必须使用安全的HTTPS公网地址');
        }
        $url = $issuer . '/.well-known/openid-configuration';
        $data = $this->jsonGet($url);
        if (empty($data['authorization_endpoint']) || empty($data['token_endpoint']) || empty($data['jwks_uri'])) {
            throw new Exception('OIDC自动发现失败');
        }
        if (empty($data['issuer']) || rtrim((string)$data['issuer'], '/') !== rtrim((string)$this->config['oidc_issuer'], '/')) {
            throw new Exception('OIDC discovery issuer验证失败');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            if (!$urlValidator->isSafeHttpsUrl($data[$field])) {
                throw new Exception('OIDC自动发现端点必须使用安全的HTTPS公网地址');
            }
        }
        if (!empty($data['userinfo_endpoint']) && !$urlValidator->isSafeHttpsUrl($data['userinfo_endpoint'])) {
            throw new Exception('OIDC自动发现端点必须使用安全的HTTPS公网地址');
        }
        $this->discovery = $data;
        return $data;
    }

    public function getAuthorizeUrl(string $state): string
    {
        $discovery = $this->discover();
        $nonce = bin2hex(random_bytes(16));
        $verifier = $this->createPkceVerifier($state);
        $this->setState($state);
        $this->saveNonce($state, $nonce);
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => $this->config['scopes'] ?: 'openid profile email',
            'nonce' => $nonce,
            'code_challenge' => $this->createPkceChallenge($verifier),
            'code_challenge_method' => 'S256',
        ]);
        return $this->appendQuery($discovery['authorization_endpoint'], $params);
    }

    public function getAccessToken(string $code, string $state = ''): OAuthTokenData
    {
        $this->setState($state);
        $discovery = $this->discover();
        $data = $this->jsonPost($discovery['token_endpoint'], [
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
            throw new Exception('OIDC获取access_token失败');
        }
        $this->tokenData = $data;
        return OAuthTokenData::fromArray($data);
    }

    public function getUserInfo(string $accessToken): OAuthUserInfo
    {
        $discovery = $this->discover();
        if (empty($this->tokenData['id_token'])) {
            throw new Exception('OIDC缺少id_token，无法验证nonce');
        }

        $userData = [];
        if (!empty($discovery['userinfo_endpoint'])) {
            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ];
            $userData = $this->jsonGet($discovery['userinfo_endpoint'], $headers);
        }

        $userinfoSub = (string)($userData['sub'] ?? '');
        $jwt = $this->verifyIdToken($this->tokenData['id_token'], $discovery, $userinfoSub);
        $sub = (string)$jwt['sub'];
        $openid = hash('sha256', rtrim((string)$discovery['issuer'], '/') . "\n" . $sub);

        return new OAuthUserInfo(
            $openid,
            $userData['nickname'] ?? $userData['preferred_username'] ?? $userData['name'] ?? ($jwt['nickname'] ?? $jwt['preferred_username'] ?? $jwt['name'] ?? ''),
            $userData['email'] ?? ($jwt['email'] ?? ''),
            $userData['picture'] ?? ($jwt['picture'] ?? ''),
            null,
            null,
            ['userinfo' => $userData, 'id_token_payload' => $jwt, 'oidc_sub' => $sub, 'oidc_issuer' => rtrim((string)$discovery['issuer'], '/')]
        );
    }

    private function verifyIdToken(string $idToken, array $discovery, string $userinfoSub): array
    {
        [$header, $payload, $signature, $signedData] = $this->parseJwt($idToken);
        if (empty($header['alg'])) {
            throw new Exception('OIDC id_token格式不正确');
        }
        if (!in_array($header['alg'], ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'], true)) {
            throw new Exception('OIDC id_token签名算法不受支持');
        }

        $jwks = $this->jsonGet($discovery['jwks_uri']);
        $key = $this->findJwk($jwks['keys'] ?? [], (string)($header['kid'] ?? ''), $header['alg']);
        if (!$key || !$this->verifyJwtSignature($header['alg'], $signedData, $signature, $key)) {
            throw new Exception('OIDC id_token签名验证失败');
        }

        $this->validateClaims($payload, $discovery, $userinfoSub);
        return $payload;
    }

    private function parseJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new Exception('OIDC id_token格式不正确');
        }
        $header = $this->jsonDecodeBase64Url($parts[0]);
        $payload = $this->jsonDecodeBase64Url($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);
        return [$header, $payload, $signature, $parts[0] . '.' . $parts[1]];
    }

    private function validateClaims(array $payload, array $discovery, string $userinfoSub): void
    {
        $issuer = rtrim((string)($discovery['issuer'] ?? $this->config['oidc_issuer']), '/');
        if (empty($payload['iss']) || rtrim((string)$payload['iss'], '/') !== $issuer) {
            throw new Exception('OIDC issuer验证失败');
        }

        $audiences = is_array($payload['aud'] ?? null) ? $payload['aud'] : [$payload['aud'] ?? null];
        if (!in_array($this->config['client_id'], $audiences, true)) {
            throw new Exception('OIDC audience验证失败');
        }
        if (count($audiences) > 1 && (($payload['azp'] ?? '') !== $this->config['client_id'])) {
            throw new Exception('OIDC azp验证失败');
        }

        $now = time();
        if (empty($payload['exp']) || (int)$payload['exp'] < $now) {
            throw new Exception('OIDC id_token已过期');
        }
        if (!empty($payload['nbf']) && (int)$payload['nbf'] > $now + 60) {
            throw new Exception('OIDC id_token尚未生效');
        }
        if (!empty($payload['iat']) && (int)$payload['iat'] > $now + 60) {
            throw new Exception('OIDC id_token签发时间无效');
        }

        $savedNonce = $this->consumeNonce();
        if (empty($savedNonce)) {
            throw new Exception('OIDC nonce不存在，请重新登录');
        }
        if (empty($payload['nonce']) || !hash_equals((string)$savedNonce, (string)$payload['nonce'])) {
            throw new Exception('OIDC nonce验证失败');
        }
        if (empty($payload['sub'])) {
            throw new Exception('OIDC用户标识验证失败');
        }
        if ($userinfoSub !== '' && !hash_equals((string)$payload['sub'], $userinfoSub)) {
            throw new Exception('OIDC用户标识验证失败');
        }
    }

    private function appendQuery(string $url, string $query): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . $query;
    }

    private function saveNonce(string $state, string $nonce): void
    {
        $nonceMap = session('oidc_nonce_map') ?: [];
        if (!is_array($nonceMap)) {
            $nonceMap = [];
        }
        $now = time();
        foreach ($nonceMap as $key => $value) {
            if (!is_array($value) || (int)($value['expires_at'] ?? 0) < $now) {
                unset($nonceMap[$key]);
            }
        }
        $nonceMap[$state] = ['nonce' => $nonce, 'expires_at' => $now + 600];
        session('oidc_nonce_map', $nonceMap);
    }

    private function consumeNonce(): string
    {
        if ($this->state === '') {
            return '';
        }
        $nonceMap = session('oidc_nonce_map') ?: [];
        if (!is_array($nonceMap) || empty($nonceMap[$this->state]) || !is_array($nonceMap[$this->state])) {
            return '';
        }
        $entry = $nonceMap[$this->state];
        unset($nonceMap[$this->state]);
        session('oidc_nonce_map', $nonceMap);
        if ((int)($entry['expires_at'] ?? 0) < time()) {
            return '';
        }
        return (string)($entry['nonce'] ?? '');
    }

    private function findJwk(array $keys, string $kid, string $alg): ?array
    {
        $matches = [];
        foreach ($keys as $key) {
            if (!$this->isJwkAllowedForAlg($key, $alg)) {
                continue;
            }
            if ($kid !== '') {
                if (($key['kid'] ?? '') === $kid) {
                    return $key;
                }
                continue;
            }
            $matches[] = $key;
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function isJwkAllowedForAlg(array $key, string $alg): bool
    {
        if (!empty($key['alg']) && $key['alg'] !== $alg) {
            return false;
        }
        if (!empty($key['use']) && $key['use'] !== 'sig') {
            return false;
        }
        if (!empty($key['key_ops']) && (!is_array($key['key_ops']) || !in_array('verify', $key['key_ops'], true))) {
            return false;
        }
        if (str_starts_with($alg, 'RS')) {
            return ($key['kty'] ?? '') === 'RSA' && !empty($key['n']) && !empty($key['e']);
        }
        $expectedCurve = match ($alg) {
            'ES256' => 'P-256',
            'ES384' => 'P-384',
            'ES512' => 'P-521',
            default => '',
        };
        return $expectedCurve !== ''
            && ($key['kty'] ?? '') === 'EC'
            && ($key['crv'] ?? '') === $expectedCurve
            && !empty($key['x'])
            && !empty($key['y']);
    }

    private function verifyJwtSignature(string $alg, string $signedData, string $signature, array $key): bool
    {
        $publicKey = $this->buildPublicKey($key);
        if ($publicKey === '') {
            return false;
        }
        if (str_starts_with($alg, 'ES')) {
            $signature = $this->joseToDerSignature($signature, $alg);
        }
        $result = @openssl_verify($signedData, $signature, $publicKey, $this->opensslAlgorithm($alg));
        if ($result === false) {
            throw new Exception('OIDC id_token签名验证失败：当前 PHP OpenSSL 不支持 ' . $alg . ' 签名算法');
        }
        return $result === 1;
    }

    private function buildPublicKey(array $key): string
    {
        if (($key['kty'] ?? '') === 'RSA' && !empty($key['n']) && !empty($key['e'])) {
            return $this->rsaPublicKeyFromComponents($this->base64UrlDecode($key['n']), $this->base64UrlDecode($key['e']));
        }
        if (($key['kty'] ?? '') === 'EC' && !empty($key['crv']) && !empty($key['x']) && !empty($key['y'])) {
            return $this->ecPublicKeyFromComponents($key['crv'], $this->base64UrlDecode($key['x']), $this->base64UrlDecode($key['y']));
        }
        return '';
    }

    private function rsaPublicKeyFromComponents(string $modulus, string $exponent): string
    {
        $sequence = $this->asn1Sequence(
            $this->asn1Integer($modulus) .
            $this->asn1Integer($exponent)
        );
        $bitString = $this->asn1BitString($sequence);
        $algorithm = $this->asn1Sequence(
            $this->asn1ObjectIdentifier('1.2.840.113549.1.1.1') .
            $this->asn1Null()
        );
        return $this->pemEncode($this->asn1Sequence($algorithm . $bitString), 'PUBLIC KEY');
    }

    private function ecPublicKeyFromComponents(string $curve, string $x, string $y): string
    {
        $curveOid = match ($curve) {
            'P-256' => '1.2.840.10045.3.1.7',
            'P-384' => '1.3.132.0.34',
            'P-521' => '1.3.132.0.35',
            default => '',
        };
        if ($curveOid === '') {
            return '';
        }
        $algorithm = $this->asn1Sequence(
            $this->asn1ObjectIdentifier('1.2.840.10045.2.1') .
            $this->asn1ObjectIdentifier($curveOid)
        );
        return $this->pemEncode($this->asn1Sequence($algorithm . $this->asn1BitString("\x04" . $x . $y)), 'PUBLIC KEY');
    }

    private function joseToDerSignature(string $signature, string $alg): string
    {
        $expectedLength = match ($alg) {
            'ES256' => 64,
            'ES384' => 96,
            'ES512' => 132,
            default => 0,
        };
        if ($expectedLength === 0 || strlen($signature) !== $expectedLength) {
            throw new Exception('OIDC ECDSA签名长度无效');
        }
        $length = intdiv($expectedLength, 2);
        return $this->asn1Sequence(
            $this->asn1Integer(substr($signature, 0, $length)) .
            $this->asn1Integer(substr($signature, $length))
        );
    }

    private function opensslAlgorithm(string $alg): string
    {
        return match ($alg) {
            'RS256', 'ES256' => 'sha256',
            'RS384', 'ES384' => 'sha384',
            'RS512', 'ES512' => 'sha512',
            default => 'sha256',
        };
    }

    private function jsonDecodeBase64Url(string $value): array
    {
        $data = json_decode($this->base64UrlDecode($value), true);
        if (!is_array($data)) {
            throw new Exception('OIDC id_token格式不正确');
        }
        return $data;
    }

    private function base64UrlDecode(string $value): string
    {
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new Exception('OIDC id_token格式不正确');
        }
        return $decoded;
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1Integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        }
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->asn1Length(strlen($value)) . $value;
    }

    private function asn1BitString(string $value): string
    {
        return "\x03" . $this->asn1Length(strlen($value) + 1) . "\x00" . $value;
    }

    private function asn1Null(): string
    {
        return "\x05\x00";
    }

    private function asn1ObjectIdentifier(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $body = chr($parts[0] * 40 + $parts[1]);
        foreach (array_slice($parts, 2) as $part) {
            $bytes = [chr($part & 0x7f)];
            $part >>= 7;
            while ($part > 0) {
                array_unshift($bytes, chr(($part & 0x7f) | 0x80));
                $part >>= 7;
            }
            $body .= implode('', $bytes);
        }
        return "\x06" . $this->asn1Length(strlen($body)) . $body;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function pemEncode(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }
}
