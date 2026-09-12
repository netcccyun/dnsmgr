<?php

namespace app\service;

use Exception;

class AxisNowService
{
    private const BASE_URL = 'https://api.axisnow.io/client/v1';
    private const PAGE_SIZE = 500;

    private string $token;
    private bool $proxy;

    public function __construct(array $config)
    {
        $this->token = trim((string)($config['token'] ?? ''));
        $this->proxy = intval($config['proxy'] ?? 0) === 1;
        if ($this->token === '') {
            throw new Exception('API 令牌不能为空');
        }
    }

    public function check(): bool
    {
        $this->request('GET', '/dns_routing_domains', ['page' => 1, 'per_page' => 5]);
        $probeRuleUuid = '00000000-0000-4000-8000-000000000000';
        $rules = $this->request('GET', '/dns_routing_rules', ['page' => 1, 'per_page' => 5]);
        if (!empty($rules['result'][0]['uuid'])) {
            $probeRuleUuid = (string)$rules['result'][0]['uuid'];
        }
        $this->listDnsRecordsByRuleUuids([$probeRuleUuid]);
        $this->listLatestRuleEventsByRuleUuids([$probeRuleUuid]);
        return true;
    }

    public function listDomains(): array
    {
        return $this->listAll('/dns_routing_domains');
    }

    public function getDomain(string $uuid): array
    {
        return $this->result($this->request('GET', '/dns_routing_domains/' . rawurlencode($uuid)));
    }

    public function createDomain(array $data): array
    {
        return $this->result($this->request('POST', '/dns_routing_domains', [], $data));
    }

    public function updateDomain(string $uuid, array $data): array
    {
        return $this->result($this->request('PUT', '/dns_routing_domains/' . rawurlencode($uuid), [], $data));
    }

    public function deleteDomain(string $uuid): void
    {
        $this->request('DELETE', '/dns_routing_domains/' . rawurlencode($uuid));
    }

    public function listRules(): array
    {
        return $this->listAll('/dns_routing_rules');
    }

    public function listDnsRecordsByRuleUuids(array $ruleUuids): array
    {
        $ruleUuids = array_values(array_unique(array_filter(array_map(
            static fn($uuid) => strtolower(trim((string)$uuid)),
            $ruleUuids
        ))));
        $rows = [];
        foreach (array_chunk($ruleUuids, 100) as $chunk) {
            $rows = array_merge($rows, $this->listAllPost('/dns_records/filter', [
                'scope' => 'all',
                'filter' => [
                    'or' => [[
                        'and' => [[
                            'field' => 'rule_uuid',
                            'operator' => 'in',
                            'value' => $chunk,
                        ]],
                    ]],
                ],
            ]));
        }
        return $rows;
    }

    public function listLatestRuleEventsByRuleUuids(array $ruleUuids): array
    {
        $ruleUuids = array_values(array_unique(array_filter(array_map(
            static fn($uuid) => strtolower(trim((string)$uuid)),
            $ruleUuids
        ))));
        $rows = [];
        foreach (array_chunk($ruleUuids, 100) as $chunk) {
            $rows = array_merge($rows, $this->listAllPost('/dns_routing_rules/events/filter', [
                'scope' => 'all',
                'filter' => [
                    'or' => [[
                        'and' => [[
                            'field' => 'latest_events_by_rule_uuids',
                            'operator' => 'in',
                            'value' => $chunk,
                        ]],
                    ]],
                ],
            ]));
        }
        return $rows;
    }

    public function getRule(string $uuid): array
    {
        return $this->result($this->request('GET', '/dns_routing_rules/' . rawurlencode($uuid)));
    }

    public function createRule(array $data): array
    {
        return $this->result($this->request('POST', '/dns_routing_rules', [], $data));
    }

    public function updateRule(string $uuid, array $data): array
    {
        return $this->result($this->request('PUT', '/dns_routing_rules/' . rawurlencode($uuid), [], $data));
    }

    public function deleteRule(string $uuid): void
    {
        $this->request('DELETE', '/dns_routing_rules/' . rawurlencode($uuid));
    }

    public function listEips(): array
    {
        return $this->listAll('/eips');
    }

    public function listSubscribedEips(): array
    {
        $rows = $this->listAllPost('/eips/resolve', ['scope' => 'subscriptions']);
        foreach ($rows as &$row) {
            $row['subscription_uuid'] = $row['uuid'] ?? '';
            $row['uuid'] = $row['eip_uuid'] ?? ($row['uuid'] ?? '');
            $row['address'] = $row['eip_address'] ?? ($row['address'] ?? '');
            $row['data_origin'] = 'subscribed';
            $row['subscription_status'] = $row['status'] ?? '';
            $row['referenced_count'] = intval($row['referenced_count'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    public function resolveSubscriptionProviders(array $tenantUuids): array
    {
        $tenantUuids = array_values(array_unique(array_filter(array_map(
            static fn($uuid) => strtolower(trim((string)$uuid)),
            $tenantUuids
        ))));
        $providers = [];
        foreach (array_chunk($tenantUuids, 500) as $chunk) {
            $rows = $this->result($this->request('POST', '/tenant/resolve', [], [
                'scope' => 'dns_routing_subscription_sharer',
                'tenant_uuids' => $chunk,
            ]));
            foreach ($rows as $row) {
                $uuid = strtolower((string)($row['tenant_uuid'] ?? ''));
                if ($uuid !== '') {
                    $providers[$uuid] = $row;
                }
            }
        }
        return $providers;
    }

    public function getEip(string $uuid): array
    {
        return $this->result($this->request('GET', '/eips/' . rawurlencode($uuid)));
    }

    public function createEips(array $data): array
    {
        return $this->result($this->request('POST', '/eips', [], $data));
    }

    public function updateEip(string $uuid, array $data): array
    {
        return $this->result($this->request('PUT', '/eips/' . rawurlencode($uuid), [], $data));
    }

    public function deleteEips(array $uuids): void
    {
        $this->request('DELETE', '/eips', [], ['uuids' => array_values($uuids)]);
    }

    public function listTags(): array
    {
        return $this->listAll('/tags', ['entity_type' => 'eip']);
    }

    public function listProbeTemplates(): array
    {
        return $this->listAll('/edge_probe/templates');
    }

    public function getGeoIspMetadata(): array
    {
        $cacheKey = 'axisnow_geo_isp_metadata_v1';
        $cached = cache($cacheKey);
        if (is_array($cached) && $cached) {
            return $cached;
        }

        $result = $this->result($this->request('GET', '/policy/metadata/geo_isp'));
        if ($result) {
            cache($cacheKey, $result, 86400);
        }
        return $result;
    }

    public function getTag(string $uuid): array
    {
        return $this->result($this->request('GET', '/tags/' . rawurlencode($uuid)));
    }

    public function createTag(array $data): array
    {
        $data['entity_type'] = 'eip';
        return $this->result($this->request('POST', '/tags', [], $data));
    }

    public function updateTag(string $uuid, array $data): array
    {
        return $this->result($this->request('PUT', '/tags/' . rawurlencode($uuid), [], $data));
    }

    public function deleteTag(string $uuid): void
    {
        $this->request('DELETE', '/tags/' . rawurlencode($uuid));
    }

    public function listDnsProviders(): array
    {
        return $this->listAll('/dns_providers');
    }

    public function listSystemDnsProviders(): array
    {
        return $this->listAllPost('/system_dns_providers');
    }

    public function listEdges(): array
    {
        return $this->listAll('/edges');
    }

    public function listClusters(): array
    {
        return $this->listAll('/clusters');
    }

    private function listAll(string $path, array $query = []): array
    {
        $rows = [];
        for ($page = 1; $page <= 200; $page++) {
            $payload = $this->request('GET', $path, array_merge($query, [
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
            ]));
            $part = $payload['result'] ?? [];
            if (!is_array($part)) {
                throw new Exception('AxisNow 返回的列表格式无效');
            }
            $rows = array_merge($rows, $part);
            $count = intval($payload['result_info']['count'] ?? 0);
            if (count($part) < self::PAGE_SIZE || ($count > 0 && count($rows) >= $count)) {
                break;
            }
        }
        return $rows;
    }

    private function listAllPost(string $path, array $data = []): array
    {
        $rows = [];
        for ($page = 1; $page <= 200; $page++) {
            $payload = $this->request('POST', $path, [], array_merge($data, [
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
            ]));
            $part = $payload['result'] ?? [];
            if (!is_array($part)) {
                throw new Exception('AxisNow 返回的列表格式无效');
            }
            $rows = array_merge($rows, $part);
            $count = intval($payload['result_info']['count'] ?? 0);
            if (count($part) < self::PAGE_SIZE || ($count > 0 && count($rows) >= $count)) {
                break;
            }
        }
        return $rows;
    }

    private function request(string $method, string $path, array $query = [], ?array $data = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ];
        $body = null;
        $url = self::BASE_URL . $path;

        if ($method === 'GET' && $query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } elseif ($data !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new Exception('AxisNow 请求数据编码失败');
            }
        }

        $response = http_request($url, $body, null, null, $headers, $this->proxy, $method, 30);
        $payload = json_decode((string)$response['body'], true);
        $code = intval($response['code'] ?? 0);
        if ($code >= 200 && $code < 300 && is_array($payload) && ($payload['success'] ?? true) !== false) {
            return $payload;
        }

        throw new Exception($this->extractError($payload, (string)($response['body'] ?? ''), $code));
    }

    private function result(array $payload): array
    {
        $result = $payload['result'] ?? [];
        return is_array($result) ? $result : [];
    }

    private function extractError($payload, string $body, int $code): string
    {
        $messages = [];
        if (is_array($payload)) {
            foreach (['errors', 'messages'] as $key) {
                foreach (($payload[$key] ?? []) as $item) {
                    if (is_array($item) && !empty($item['message'])) {
                        $messages[] = (string)$item['message'];
                    } elseif (is_string($item) && $item !== '') {
                        $messages[] = $item;
                    }
                }
            }
            if (!empty($payload['message']) && is_string($payload['message'])) {
                $messages[] = $payload['message'];
            }
        }
        $message = implode('；', array_unique($messages));
        if ($message === '' && $body !== '') {
            $message = mb_substr(strip_tags($body), 0, 300);
        }
        if ($message === '') {
            $message = '请求失败';
        }
        return ($code > 0 ? 'HTTP ' . $code . '：' : '') . $message;
    }
}
