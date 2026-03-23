<?php

namespace app\service;

use Exception;

class CloudflareEnhanceService
{
    private string $email = '';
    private string $apiKey = '';
    private int $auth = 0;
    private bool $proxy = false;
    private string $accountId = '';
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';

    public function __construct(array $config = [])
    {
        $this->email = trim((string)($config['email'] ?? ''));
        $this->apiKey = preg_replace('/\s+/', '', trim((string)($config['apikey'] ?? '')));
        $this->auth = isset($config['auth']) ? intval($config['auth']) : (preg_match('/^[0-9a-f]+$/i', $this->apiKey) ? 0 : 1);
        $this->proxy = isset($config['proxy']) && strval($config['proxy']) === '1';
        $this->accountId = trim((string)($config['account_id'] ?? ''));
    }

    public function isApiTokenAuth(): bool
    {
        return $this->auth === 1;
    }

    public function getConfiguredAccountId(): string
    {
        return $this->accountId;
    }

    public function getAccounts(): array
    {
        try {
            return $this->paginate('/accounts', [], 50);
        } catch (Exception $e) {
            $this->throwActionError('获取账户列表', $e, 'Account:Read');
        }
    }

    public function getDefaultAccountId(): string
    {
        try {
            $accounts = $this->getAccounts();
            if (!empty($accounts[0]['id'])) {
                return trim((string)$accounts[0]['id']);
            }
        } catch (Exception $e) {
        }

        try {
            $payload = $this->requestRaw('GET', '/zones', ['page' => 1, 'per_page' => 1]);
            $first = $payload['result'][0] ?? [];
            $accountId = trim((string)($first['account']['id'] ?? ''));
            if ($accountId !== '') {
                return $accountId;
            }
        } catch (Exception $e) {
        }

        return '';
    }

    public function getZone(string $zoneId): array
    {
        try {
            return $this->requestResult('GET', '/zones/' . $zoneId);
        } catch (Exception $e) {
            $this->throwActionError('获取域名详情', $e, 'Zone:Read');
        }
    }

    public function listCustomHostnames(string $zoneId): array
    {
        try {
            return $this->paginate('/zones/' . $zoneId . '/custom_hostnames', [], 100);
        } catch (Exception $e) {
            $this->throwActionError('获取自定义主机名列表', $e, 'SSL and Certificates:Read');
        }
    }

    public function getCustomHostname(string $zoneId, string $hostnameId): array
    {
        try {
            return $this->requestResult('GET', '/zones/' . $zoneId . '/custom_hostnames/' . trim($hostnameId));
        } catch (Exception $e) {
            $this->throwActionError('获取自定义主机名详情', $e, 'SSL and Certificates:Read');
        }
    }

    public function createCustomHostname(string $zoneId, string $hostname, ?string $customOriginServer = null): array
    {
        $hostname = $this->normalizeHostname($hostname);
        $payload = [
            'hostname' => $hostname,
            'ssl' => [
                'method' => 'http',
                'type' => 'dv',
            ],
        ];
        $origin = trim((string)$customOriginServer);
        if ($origin !== '') {
            $payload['custom_origin_server'] = $this->normalizeHostname($origin);
        }

        try {
            return $this->requestResult('POST', '/zones/' . $zoneId . '/custom_hostnames', [], $payload);
        } catch (Exception $e) {
            $this->throwActionError('创建自定义主机名', $e, 'SSL and Certificates:Write');
        }
    }

    public function updateCustomHostname(string $zoneId, string $hostnameId, array $payload): array
    {
        if (isset($payload['custom_origin_server']) && $payload['custom_origin_server'] !== null) {
            $payload['custom_origin_server'] = $this->normalizeHostname($payload['custom_origin_server']);
        }
        if (isset($payload['hostname']) && $payload['hostname'] !== null) {
            $payload['hostname'] = $this->normalizeHostname($payload['hostname']);
        }

        try {
            return $this->requestResult('PATCH', '/zones/' . $zoneId . '/custom_hostnames/' . trim($hostnameId), [], $payload);
        } catch (Exception $e) {
            $this->throwActionError('更新自定义主机名', $e, 'SSL and Certificates:Write');
        }
    }

    public function deleteCustomHostname(string $zoneId, string $hostnameId): bool
    {
        try {
            $this->requestResult('DELETE', '/zones/' . $zoneId . '/custom_hostnames/' . $hostnameId);
            return true;
        } catch (Exception $e) {
            $this->throwActionError('删除自定义主机名', $e, 'SSL and Certificates:Write');
        }
    }

    public function getFallbackOrigin(string $zoneId): string
    {
        try {
            $result = $this->requestResult('GET', '/zones/' . $zoneId . '/custom_hostnames/fallback_origin', [], null, true);
            if ($result === null) {
                return '';
            }
            return trim((string)($result['origin'] ?? ''));
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                return '';
            }
            $this->throwActionError('获取 Fallback Origin', $e, 'SSL and Certificates:Read');
        }
    }

    public function updateFallbackOrigin(string $zoneId, string $origin): string
    {
        try {
            $result = $this->requestResult('PUT', '/zones/' . $zoneId . '/custom_hostnames/fallback_origin', [], [
                'origin' => $this->normalizeHostname($origin),
            ]);
            return trim((string)($result['origin'] ?? $origin));
        } catch (Exception $e) {
            $this->throwActionError('更新 Fallback Origin', $e, 'SSL and Certificates:Write');
        }
    }

    public function deleteFallbackOrigin(string $zoneId): bool
    {
        try {
            $this->requestResult('DELETE', '/zones/' . $zoneId . '/custom_hostnames/fallback_origin', [], null, true);
            return true;
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                return true;
            }
            $this->throwActionError('删除 Fallback Origin', $e, 'SSL and Certificates:Write');
        }
    }

    public function listTunnels(string $accountId): array
    {
        $this->assertTunnelSupported();
        try {
            return $this->paginate('/accounts/' . $accountId . '/cfd_tunnel', ['is_deleted' => 'false'], 100);
        } catch (Exception $e) {
            $this->throwActionError('获取 Tunnel 列表', $e, 'Cloudflare Tunnel:Read');
        }
    }

    public function createTunnel(string $accountId, string $name): array
    {
        $this->assertTunnelSupported();
        try {
            return $this->requestResult('POST', '/accounts/' . $accountId . '/cfd_tunnel', [], [
                'name' => trim($name),
                'tunnel_secret' => base64_encode(random_bytes(32)),
            ]);
        } catch (Exception $e) {
            $this->throwActionError('创建 Tunnel', $e, 'Cloudflare Tunnel:Write');
        }
    }

    public function deleteTunnel(string $accountId, string $tunnelId): bool
    {
        $this->assertTunnelSupported();
        try {
            $this->requestResult('DELETE', '/accounts/' . $accountId . '/cfd_tunnel/' . $tunnelId);
            return true;
        } catch (Exception $e) {
            $this->throwActionError('删除 Tunnel', $e, 'Cloudflare Tunnel:Write');
        }
    }

    public function getTunnelToken(string $accountId, string $tunnelId): string
    {
        $this->assertTunnelSupported();
        try {
            $result = $this->requestResult('GET', '/accounts/' . $accountId . '/cfd_tunnel/' . $tunnelId . '/token');
            if (is_string($result)) {
                return $result;
            }
            return trim((string)($result['token'] ?? ''));
        } catch (Exception $e) {
            $this->throwActionError('获取 Tunnel Token', $e, 'Cloudflare Tunnel:Read');
        }
    }

    public function getTunnelConfig(string $accountId, string $tunnelId): array
    {
        $this->assertTunnelSupported();
        try {
            $result = $this->requestResult('GET', '/accounts/' . $accountId . '/cfd_tunnel/' . $tunnelId . '/configurations', [], null, true);
            return is_array($result) ? $result : [];
        } catch (Exception $e) {
            $this->throwActionError('获取 Tunnel 配置', $e, 'Cloudflare Tunnel:Read');
        }
    }

    public function updateTunnelConfig(string $accountId, string $tunnelId, array $config): array
    {
        $this->assertTunnelSupported();
        try {
            return $this->requestResult('PUT', '/accounts/' . $accountId . '/cfd_tunnel/' . $tunnelId . '/configurations', [], [
                'config' => $config,
            ]);
        } catch (Exception $e) {
            $this->throwActionError('更新 Tunnel 配置', $e, 'Cloudflare Tunnel:Write');
        }
    }

    public function listCidrRoutes(string $accountId, ?string $tunnelId = null): array
    {
        $this->assertTunnelSupported();
        $query = ['is_deleted' => 'false'];
        if (!empty($tunnelId)) {
            $query['tunnel_id'] = $tunnelId;
        }

        try {
            return $this->paginate('/accounts/' . $accountId . '/teamnet/routes', $query, 100);
        } catch (Exception $e) {
            $this->throwActionError('获取 CIDR 路由列表', $e, 'Cloudflare Tunnel:Read');
        }
    }

    public function createCidrRoute(string $accountId, string $tunnelId, string $network, ?string $comment = null, ?string $virtualNetworkId = null): array
    {
        $this->assertTunnelSupported();
        $payload = [
            'network' => trim($network),
            'tunnel_id' => trim($tunnelId),
        ];
        if (!empty($comment)) {
            $payload['comment'] = trim($comment);
        }
        if (!empty($virtualNetworkId)) {
            $payload['virtual_network_id'] = trim($virtualNetworkId);
        }

        try {
            return $this->requestResult('POST', '/accounts/' . $accountId . '/teamnet/routes', [], $payload);
        } catch (Exception $e) {
            $this->throwActionError('创建 CIDR 路由', $e, 'Cloudflare Tunnel:Write');
        }
    }

    public function deleteCidrRoute(string $accountId, string $routeId): bool
    {
        $this->assertTunnelSupported();
        try {
            $this->requestResult('DELETE', '/accounts/' . $accountId . '/teamnet/routes/' . $routeId);
            return true;
        } catch (Exception $e) {
            $this->throwActionError('删除 CIDR 路由', $e, 'Cloudflare Tunnel:Write');
        }
    }

    public function listHostnameRoutes(string $accountId, ?string $tunnelId = null): array
    {
        $this->assertTunnelSupported();
        $query = ['is_deleted' => 'false'];
        if (!empty($tunnelId)) {
            $query['tunnel_id'] = $tunnelId;
        }

        try {
            return $this->paginate('/accounts/' . $accountId . '/zerotrust/routes/hostname', $query, 100);
        } catch (Exception $e) {
            $this->throwActionError('获取主机名路由列表', $e, 'Cloudflare Tunnel:Read');
        }
    }

    public function createHostnameRoute(string $accountId, string $tunnelId, string $hostname, ?string $comment = null): array
    {
        $this->assertTunnelSupported();
        $payload = [
            'hostname' => $this->normalizeHostname($hostname),
            'tunnel_id' => trim($tunnelId),
        ];
        if (!empty($comment)) {
            $payload['comment'] = trim($comment);
        }

        try {
            return $this->requestResult('POST', '/accounts/' . $accountId . '/zerotrust/routes/hostname', [], $payload);
        } catch (Exception $e) {
            $this->throwActionError('创建主机名路由', $e, 'Cloudflare Tunnel:Write');
        }
    }

    public function deleteHostnameRoute(string $accountId, string $routeId): bool
    {
        $this->assertTunnelSupported();
        try {
            $this->requestResult('DELETE', '/accounts/' . $accountId . '/zerotrust/routes/hostname/' . $routeId);
            return true;
        } catch (Exception $e) {
            $this->throwActionError('删除主机名路由', $e, 'Cloudflare Tunnel:Write');
        }
    }

    public function upsertTunnelCnameRecord(string $zoneId, string $hostname, string $tunnelId): array
    {
        $zoneId = trim($zoneId);
        $hostname = $this->normalizeHostname($hostname);
        $target = trim($tunnelId) . '.cfargotunnel.com';

        try {
            $payload = $this->requestRaw('GET', '/zones/' . $zoneId . '/dns_records', [
                'name' => $hostname,
                'type' => 'CNAME',
                'page' => 1,
                'per_page' => 100,
            ]);
            $records = $payload['result'] ?? [];

            $allByNamePayload = $this->requestRaw('GET', '/zones/' . $zoneId . '/dns_records', [
                'name' => $hostname,
                'page' => 1,
                'per_page' => 100,
            ]);
            $allByName = $allByNamePayload['result'] ?? [];
            $otherTypes = [];
            foreach ($allByName as $row) {
                $type = strtoupper((string)($row['type'] ?? ''));
                $name = $this->normalizeHostname($row['name'] ?? '');
                if ($name === $hostname && $type !== 'CNAME') {
                    $otherTypes[] = $type;
                }
            }
            if (!empty($otherTypes)) {
                $otherTypes = array_unique(array_filter($otherTypes));
                throw new Exception('主机名已存在非 CNAME 记录（' . implode(', ', $otherTypes) . '），无法同步 Tunnel CNAME', 400);
            }

            foreach ($records as $record) {
                $name = $this->normalizeHostname($record['name'] ?? '');
                if ($name !== $hostname) {
                    continue;
                }
                $content = $this->normalizeHostname($record['content'] ?? '');
                $proxied = !empty($record['proxied']);
                if ($content === $this->normalizeHostname($target) && $proxied) {
                    return ['action' => 'unchanged'];
                }

                $this->requestResult('PUT', '/zones/' . $zoneId . '/dns_records/' . $record['id'], [], [
                    'type' => 'CNAME',
                    'name' => $hostname,
                    'content' => $target,
                    'proxied' => true,
                    'ttl' => 1,
                ]);
                return ['action' => 'updated'];
            }

            $this->requestResult('POST', '/zones/' . $zoneId . '/dns_records', [], [
                'type' => 'CNAME',
                'name' => $hostname,
                'content' => $target,
                'proxied' => true,
                'ttl' => 1,
            ]);
            return ['action' => 'created'];
        } catch (Exception $e) {
            $this->throwActionError('同步 Tunnel CNAME 记录', $e, 'Zone:DNS:Edit');
        }
    }

    public function deleteTunnelCnameRecordIfMatch(string $zoneId, string $hostname, string $tunnelId): array
    {
        $zoneId = trim($zoneId);
        $hostname = $this->normalizeHostname($hostname);
        $target = $this->normalizeHostname(trim($tunnelId) . '.cfargotunnel.com');

        try {
            $payload = $this->requestRaw('GET', '/zones/' . $zoneId . '/dns_records', [
                'name' => $hostname,
                'type' => 'CNAME',
                'page' => 1,
                'per_page' => 100,
            ]);
            $records = $payload['result'] ?? [];
            foreach ($records as $record) {
                $name = $this->normalizeHostname($record['name'] ?? '');
                $content = $this->normalizeHostname($record['content'] ?? '');
                if ($name === $hostname && $content === $target) {
                    $this->requestResult('DELETE', '/zones/' . $zoneId . '/dns_records/' . $record['id']);
                    return ['deleted' => true];
                }
            }
            return ['deleted' => false];
        } catch (Exception $e) {
            $this->throwActionError('删除 Tunnel CNAME 记录', $e, 'Zone:DNS:Edit');
        }
    }

    private function paginate(string $path, array $query = [], int $perPage = 100): array
    {
        $all = [];
        $page = 1;
        $maxPage = 200;
        while ($page <= $maxPage) {
            $payload = $this->requestRaw('GET', $path, array_merge($query, [
                'page' => $page,
                'per_page' => $perPage,
            ]));
            $batch = $payload['result'] ?? [];
            if (!is_array($batch)) {
                $batch = [];
            }
            foreach ($batch as $item) {
                $all[] = $item;
            }

            $totalPages = intval($payload['result_info']['total_pages'] ?? 0);
            if ($totalPages > 0) {
                if ($page >= $totalPages) {
                    break;
                }
            } elseif (count($batch) < $perPage || empty($batch)) {
                break;
            }
            $page++;
        }
        return $all;
    }

    private function requestResult(string $method, string $path, array $query = [], ?array $body = null, bool $allowNotFound = false)
    {
        $payload = $this->requestRaw($method, $path, $query, $body, $allowNotFound);
        if ($payload === null) {
            return null;
        }
        return $payload['result'] ?? [];
    }

    private function requestRaw(string $method, string $path, array $query = [], ?array $body = null, bool $allowNotFound = false): ?array
    {
        $headers = $this->buildHeaders($body !== null);
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $response = http_request(
            $url,
            $body,
            null,
            null,
            $headers,
            $this->proxy,
            strtoupper($method),
            20
        );

        $status = intval($response['code'] ?? 0);
        if ($allowNotFound && $status === 404) {
            return null;
        }

        $payload = json_decode($response['body'] ?? '', true);
        if (!is_array($payload)) {
            throw new Exception('Cloudflare 返回数据解析失败', $status > 0 ? $status : 502);
        }

        if (($payload['success'] ?? false) !== true) {
            if ($allowNotFound && $status === 404) {
                return null;
            }
            $message = $this->extractErrorMessage($payload);
            throw new Exception($message !== '' ? $message : 'Cloudflare API 请求失败', $status > 0 ? $status : 400);
        }

        return $payload;
    }

    private function buildHeaders(bool $json = false): array
    {
        if ($this->apiKey === '') {
            throw new Exception('Cloudflare API 凭证为空', 400);
        }

        if ($this->auth === 1) {
            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ];
        } else {
            if ($this->email === '') {
                throw new Exception('当前 Cloudflare 账户缺少邮箱地址，旧版 API Key 认证需要填写邮箱', 400);
            }
            $headers = [
                'X-Auth-Email' => $this->email,
                'X-Auth-Key' => $this->apiKey,
            ];
        }

        if ($json) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function assertTunnelSupported(): void
    {
        if (!$this->isApiTokenAuth()) {
            throw new Exception('Cloudflare Tunnels 仅支持 API 令牌认证，请将当前账户的认证方式切换为 API令牌', 400);
        }
    }

    private function normalizeHostname($hostname): string
    {
        $hostname = trim((string)$hostname);
        if ($hostname === '') {
            return '';
        }
        $hostname = rtrim($hostname, '.');
        $hostname = convertDomainToAscii($hostname);
        return strtolower($hostname);
    }

    private function extractErrorMessage(array $payload): string
    {
        if (!empty($payload['errors'][0]['message'])) {
            return trim((string)$payload['errors'][0]['message']);
        }
        if (!empty($payload['messages'][0]['message'])) {
            return trim((string)$payload['messages'][0]['message']);
        }
        if (!empty($payload['result']['message'])) {
            return trim((string)$payload['result']['message']);
        }
        return '';
    }

    private function throwActionError(string $action, Exception $e, string $permissionHint = ''): void
    {
        $status = intval($e->getCode());
        $message = trim($e->getMessage());

        if ($status === 401) {
            $message = 'Cloudflare 凭证无效或已过期，无法' . $action;
        } elseif ($status === 403) {
            $message = 'Cloudflare 权限不足，无法' . $action;
            if ($permissionHint !== '') {
                $message .= '。请确认 Token 具备 ' . $permissionHint . ' 权限';
            }
        } elseif ($status === 404 && $message === '') {
            $message = $action . '失败：资源不存在';
        } elseif ($status === 429) {
            $message = 'Cloudflare API 请求过于频繁，暂时无法' . $action . '，请稍后重试';
        } elseif ($status >= 500) {
            $message = 'Cloudflare 服务暂时不可用，无法' . $action . '，请稍后重试';
        } elseif ($message === '') {
            $message = $action . '失败';
        }

        throw new Exception($message, $status > 0 ? $status : 400);
    }
}
