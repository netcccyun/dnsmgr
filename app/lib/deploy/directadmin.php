<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class directadmin implements DeployInterface
{
    private $logger;
    private string $url;
    private string $username;
    private string $password;
    private bool $verifyTls;
    private bool $proxy;
    private $transport;

    public function __construct($config, $transport = null)
    {
        $this->url = rtrim(trim((string)($config['url'] ?? '')), '/');
        $this->username = trim((string)($config['username'] ?? ''));
        $this->password = (string)($config['password'] ?? '');
        $this->verifyTls = !isset($config['verify_tls']) || (string)$config['verify_tls'] !== '0';
        $this->proxy = isset($config['proxy']) && (string)$config['proxy'] === '1';
        $this->transport = $transport;

        if ($this->url !== '') {
            $parts = parse_url($this->url);
            $valid = is_array($parts)
                && strtolower((string)($parts['scheme'] ?? '')) === 'https'
                && !empty($parts['host'])
                && empty($parts['user'])
                && empty($parts['pass'])
                && empty($parts['query'])
                && empty($parts['fragment'])
                && (!isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/');
            if (!$valid) {
                throw new Exception('DirectAdmin 面板地址格式无效，必须为不带路径、凭据、查询或片段的 HTTPS 地址');
            }
        }
    }

    public function check()
    {
        $this->assertAccountConfig();
        $this->request('GET', '/CMD_API_LOGIN_TEST');
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $this->assertAccountConfig();
        $domains = $this->parseDomains((string)($config['domain'] ?? ''));
        if (empty($domains)) {
            throw new Exception('没有设置要部署的 DirectAdmin 域名');
        }
        if (trim((string)$fullchain) === '' || trim((string)$privatekey) === '') {
            throw new Exception('SSL 证书或私钥内容不能为空');
        }

        $privatekey = $this->normalizePrivateKeyForDirectAdmin((string)$privatekey);
        $certificate = rtrim($privatekey) . "\n" . ltrim((string)$fullchain);
        foreach ($domains as $domain) {
            $this->request('POST', '/CMD_API_SSL', [
                'domain' => $domain,
                'action' => 'save',
                'type' => 'paste',
                'certificate' => $certificate,
            ]);
            $this->log("DirectAdmin 域名 {$domain} 证书部署成功");
        }
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function assertAccountConfig(): void
    {
        if ($this->url === '' || $this->username === '' || $this->password === '') {
            throw new Exception('请填写 DirectAdmin 面板地址、用户名和认证密码');
        }
    }

    private function parseDomains(string $value): array
    {
        $domains = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $result = [];
        foreach ($domains ?: [] as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '' || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                throw new Exception("DirectAdmin 域名格式不正确：{$domain}");
            }
            $result[$domain] = $domain;
        }
        return array_values($result);
    }

    private function normalizePrivateKeyForDirectAdmin(string $privatekey): string
    {
        if (!str_starts_with(ltrim($privatekey), '-----BEGIN PRIVATE KEY-----')) {
            return $privatekey;
        }

        $key = openssl_pkey_get_private($privatekey);
        if ($key === false) {
            throw new Exception('SSL 私钥格式无效');
        }
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return $privatekey;
        }

        $der = preg_replace('/-----BEGIN PRIVATE KEY-----|-----END PRIVATE KEY-----|\s+/', '', $privatekey);
        $der = base64_decode((string)$der, true);
        if ($der === false) {
            throw new Exception('ECC PKCS#8 私钥 Base64 内容无效');
        }

        try {
            $offset = 0;
            [$tag, $sequence] = $this->readDerElement($der, $offset);
            if ($tag !== 0x30 || $offset !== strlen($der)) {
                throw new Exception('PKCS#8 外层结构无效');
            }
            $innerOffset = 0;
            [$versionTag] = $this->readDerElement($sequence, $innerOffset);
            [$algorithmTag, $algorithm] = $this->readDerElement($sequence, $innerOffset);
            [$keyTag, $sec1] = $this->readDerElement($sequence, $innerOffset);
            if ($versionTag !== 0x02 || $algorithmTag !== 0x30 || $keyTag !== 0x04 || $sec1 === '') {
                throw new Exception('PKCS#8 ECC 私钥结构无效');
            }

            $algorithmOffset = 0;
            [$algorithmOidTag] = $this->readDerElement($algorithm, $algorithmOffset);
            $curveStart = $algorithmOffset;
            [$curveOidTag] = $this->readDerElement($algorithm, $algorithmOffset);
            $curveElement = substr($algorithm, $curveStart, $algorithmOffset - $curveStart);
            if ($algorithmOidTag !== 0x06 || $curveOidTag !== 0x06 || $curveElement === '') {
                throw new Exception('PKCS#8 ECC 曲线参数缺失');
            }

            $sec1Offset = 0;
            [$sec1Tag, $sec1Content] = $this->readDerElement($sec1, $sec1Offset);
            if ($sec1Tag !== 0x30 || $sec1Offset !== strlen($sec1)) {
                throw new Exception('SEC1 ECC 私钥结构无效');
            }

            $contentOffset = 0;
            $versionStart = $contentOffset;
            [$sec1VersionTag] = $this->readDerElement($sec1Content, $contentOffset);
            $versionElement = substr($sec1Content, $versionStart, $contentOffset - $versionStart);
            $keyStart = $contentOffset;
            [$privateOctetTag] = $this->readDerElement($sec1Content, $contentOffset);
            $privateOctetElement = substr($sec1Content, $keyStart, $contentOffset - $keyStart);
            if ($sec1VersionTag !== 0x02 || $privateOctetTag !== 0x04) {
                throw new Exception('SEC1 ECC 私钥字段无效');
            }

            $remaining = substr($sec1Content, $contentOffset);
            if ($remaining !== '' && ord($remaining[0]) === 0xa0) {
                $sec1WithCurve = $sec1;
            } else {
                $parameters = "\xa0" . $this->encodeDerLength(strlen($curveElement)) . $curveElement;
                $newContent = $versionElement . $privateOctetElement . $parameters . $remaining;
                $sec1WithCurve = "\x30" . $this->encodeDerLength(strlen($newContent)) . $newContent;
            }
        } catch (Exception $e) {
            throw new Exception('ECC 私钥转换为 DirectAdmin 兼容格式失败：' . $e->getMessage());
        }

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($sec1WithCurve), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    private function readDerElement(string $der, int &$offset): array
    {
        $total = strlen($der);
        if ($offset + 2 > $total) {
            throw new Exception('ASN.1 数据不完整');
        }
        $tag = ord($der[$offset++]);
        $firstLength = ord($der[$offset++]);
        if (($firstLength & 0x80) === 0) {
            $length = $firstLength;
        } else {
            $lengthBytes = $firstLength & 0x7f;
            if ($lengthBytes < 1 || $lengthBytes > 4 || $offset + $lengthBytes > $total) {
                throw new Exception('ASN.1 长度字段无效');
            }
            $length = 0;
            for ($i = 0; $i < $lengthBytes; $i++) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
        }
        if ($length < 0 || $offset + $length > $total) {
            throw new Exception('ASN.1 内容长度越界');
        }
        $value = substr($der, $offset, $length);
        $offset += $length;
        return [$tag, $value];
    }

    private function encodeDerLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function request(string $method, string $path, ?array $form = null): array
    {
        $options = [
            'timeout' => 20,
            'connect_timeout' => 10,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => $this->verifyTls,
            'auth' => [$this->username, $this->password],
            'headers' => [
                'Accept' => 'application/x-www-form-urlencoded, application/json',
                'User-Agent' => 'DNSManager-DirectAdmin-Deploy/1.0',
            ],
        ];
        if ($form !== null) {
            $options['form_params'] = $form;
        }
        if ($this->proxy) {
            $options['proxy'] = $this->getProxyUrl();
        }

        $url = $this->url . $path;
        try {
            if ($this->transport) {
                $response = call_user_func($this->transport, $method, $url, $options);
            } else {
                $httpResponse = (new Client())->request($method, $url, $options);
                $response = [
                    'code' => $httpResponse->getStatusCode(),
                    'body' => $httpResponse->getBody()->getContents(),
                ];
            }
        } catch (GuzzleException $e) {
            throw new Exception('DirectAdmin 请求失败：' . guzzle_error($e));
        } catch (Exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Exception('DirectAdmin 请求失败：' . $e->getMessage());
        }

        return $this->parseResponse((int)($response['code'] ?? 0), (string)($response['body'] ?? ''));
    }

    private function parseResponse(int $httpCode, string $body): array
    {
        if ($httpCode === 401 || $httpCode === 403) {
            throw new Exception('DirectAdmin 认证失败，请检查用户名和认证密码');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("DirectAdmin 请求失败（HTTP {$httpCode}）");
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            throw new Exception('DirectAdmin 响应格式异常：响应内容为空');
        }

        $data = [];
        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('DirectAdmin 响应格式异常：JSON 无法解析');
            }
            $data = $decoded;
        } else {
            parse_str($body, $data);
        }

        if (!is_array($data) || !array_key_exists('error', $data)) {
            throw new Exception('DirectAdmin 响应格式异常：缺少 error 状态字段');
        }

        $error = $data['error'];
        if ((string)$error !== '0' && $error !== false) {
            $message = trim((string)($data['details'] ?? $data['text'] ?? 'DirectAdmin 返回未知错误'));
            throw new Exception($message !== '' ? $message : 'DirectAdmin 返回未知错误');
        }
        return $data;
    }

    private function getProxyUrl(): string
    {
        $server = trim((string)config_get('proxy_server'));
        $port = intval(config_get('proxy_port'));
        if ($server === '' || $port <= 0) {
            throw new Exception('代理服务器或端口未配置');
        }
        $scheme = match ((string)config_get('proxy_type')) {
            'https' => 'https',
            'sock4' => 'socks4',
            'sock5' => 'socks5',
            'sock5h' => 'socks5h',
            default => 'http',
        };
        $username = (string)config_get('proxy_user');
        $password = (string)config_get('proxy_pwd');
        $auth = $username !== '' || $password !== ''
            ? rawurlencode($username) . ':' . rawurlencode($password) . '@'
            : '';
        return "{$scheme}://{$auth}{$server}:{$port}";
    }

    private function log(string $text): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $text);
        }
    }
}
