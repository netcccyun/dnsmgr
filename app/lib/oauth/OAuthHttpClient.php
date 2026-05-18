<?php

namespace app\lib\oauth;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class OAuthHttpClient
{
    public function get(string $url, array $headers = []): array
    {
        return $this->request($url, null, $headers, 'GET');
    }

    public function post(string $url, array|string $data, array $headers = []): array
    {
        return $this->request($url, $data, $headers, 'POST');
    }

    public function getJson(string $url, array $headers = []): array
    {
        return $this->decodeJson($this->get($url, $headers)['body'], $url);
    }

    public function postJson(string $url, array|string $data, array $headers = []): array
    {
        return $this->decodeJson($this->post($url, $data, $headers)['body'], $url);
    }

    private function request(string $url, array|string|null $data, array $headers, string $method): array
    {
        $urlValidator = new OAuthUrlValidator();
        if (!$urlValidator->isSafeHttpsUrl($url)) {
            throw new Exception('OAuth端点URL必须使用安全的HTTPS公网地址');
        }
        $options = [
            'timeout' => 10,
            'connect_timeout' => 10,
            'allow_redirects' => false,
            'verify' => true,
            'http_errors' => false,
            'headers' => array_merge([
                'User-Agent' => 'DNSMgr OAuth Client',
            ], $headers),
        ];
        $proxyUrl = build_guzzle_proxy_url();
        if ($proxyUrl !== null) {
            $options['proxy'] = $proxyUrl;
        }

        if ($data !== null) {
            if (is_array($data)) {
                $options['form_params'] = $data;
                if (!isset($options['headers']['Content-Type'])) {
                    $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                }
            } else {
                $options['body'] = $data;
            }
        }

        try {
            $response = (new Client())->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            if ($statusCode >= 400) {
                trace('OAuth HTTP响应异常: ' . $statusCode . ' ' . $this->safeHost($url), 'error');
                throw new Exception($this->statusMessage($statusCode, $url));
            }
            return [
                'code' => $statusCode,
                'redirect_url' => $statusCode >= 300 && $statusCode < 400 ? $response->getHeaderLine('Location') : '',
                'headers' => $response->getHeaders(),
                'body' => $body,
            ];
        } catch (ConnectException $e) {
            trace('OAuth连接失败: ' . $this->safeHost($url) . ' ' . $this->redactSensitiveMessage($e->getMessage()), 'error');
            throw new Exception('无法连接到OAuth提供商（' . $this->safeHost($url) . '），请检查网络、代理或提供商地址');
        } catch (RequestException $e) {
            trace('OAuth请求异常: ' . $this->safeHost($url) . ' ' . $this->redactSensitiveMessage($e->getMessage()), 'error');
            throw new Exception($this->requestMessage($e, $url));
        } catch (GuzzleException $e) {
            trace('OAuth HTTP请求失败: ' . $this->safeHost($url) . ' ' . $this->redactSensitiveMessage($e->getMessage()), 'error');
            throw new Exception('请求OAuth提供商失败（' . $this->safeHost($url) . '），请稍后重试或检查配置');
        }
    }

    public function decodeJson(string $body, string $url = ''): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception('OAuth提供商返回的数据格式不正确（' . $this->safeHost($url) . '），请检查端点地址和响应格式');
        }
        return $data;
    }

    private function safeHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ?: '未知主机';
    }

    private function redactSensitiveMessage(string $message): string
    {
        foreach (['access_token', 'refresh_token', 'client_secret', 'appkey', 'appid', 'code', 'id_token'] as $key) {
            $message = preg_replace('/(' . preg_quote($key, '/') . '=)[^&\s]*/i', '$1[redacted]', $message);
            $message = preg_replace('/("' . preg_quote($key, '/') . '"\s*:\s*")[^"]*(")/i', '$1[redacted]$2', $message);
        }
        return $message;
    }

    private function statusMessage(int $statusCode, string $url): string
    {
        $host = $this->safeHost($url);
        return match ($statusCode) {
            400 => 'OAuth提供商拒绝请求（400，' . $host . '），请检查回调地址、授权码或请求参数',
            401, 403 => 'OAuth提供商认证失败（' . $statusCode . '，' . $host . '），请检查 Client ID 和 Client Secret',
            404 => 'OAuth端点不存在（404，' . $host . '），请检查授权、Token 或用户信息端点地址',
            429 => 'OAuth提供商请求过于频繁（429，' . $host . '），请稍后重试',
            default => 'OAuth提供商返回异常状态码（' . $statusCode . '，' . $host . '），请稍后重试或检查配置',
        };
    }

    private function requestMessage(RequestException $e, string $url): string
    {
        if ($e->getResponse()) {
            return $this->statusMessage($e->getResponse()->getStatusCode(), $url);
        }
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return '请求OAuth提供商超时（' . $this->safeHost($url) . '），请检查网络或代理设置';
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'OAuth提供商TLS证书验证失败（' . $this->safeHost($url) . '），请检查HTTPS证书或服务器时间';
        }
        return '请求OAuth提供商失败（' . $this->safeHost($url) . '），请检查网络或配置';
    }
}
