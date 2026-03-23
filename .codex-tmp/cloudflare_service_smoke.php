<?php

declare(strict_types=1);

function convertDomainToAscii($domain)
{
    return (string)$domain;
}

function http_request($url, $data = null, $referer = null, $cookie = null, $headers = null, $proxy = false, $method = null, $timeout = 10): array
{
    $method = strtoupper((string)($method ?: ($data !== null ? 'POST' : 'GET')));
    $headerLines = [
        'User-Agent: Codex-Smoke-Test/1.0',
    ];
    $normalizedHeaders = [];
    foreach ((array)$headers as $key => $value) {
        $normalizedHeaders[strtolower((string)$key)] = (string)$value;
        $headerLines[] = $key . ': ' . $value;
    }
    if ($referer) {
        $headerLines[] = 'Referer: ' . $referer;
    }
    if ($cookie) {
        $headerLines[] = 'Cookie: ' . $cookie;
    }

    $content = null;
    if ($data !== null && $method !== 'GET') {
        if (is_array($data) || is_object($data)) {
            $contentType = $normalizedHeaders['content-type'] ?? 'application/x-www-form-urlencoded';
            if (stripos($contentType, 'application/json') !== false) {
                $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $content = http_build_query((array)$data);
            }
        } else {
            $content = (string)$data;
        }
    } elseif ($data !== null && $method === 'GET' && is_array($data) && !str_contains($url, '?')) {
        $url .= '?' . http_build_query($data);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $content,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $body = '';
    }
    $responseHeaders = $http_response_header ?? [];
    $statusLine = $responseHeaders[0] ?? '';
    $statusCode = preg_match('#\s(\d{3})\s#', $statusLine, $match) ? intval($match[1]) : 0;

    return [
        'code' => $statusCode,
        'headers' => $responseHeaders,
        'body' => $body,
    ];
}

require __DIR__ . '/../app/service/CloudflareEnhanceService.php';

use app\service\CloudflareEnhanceService;

$token = getenv('CF_API_TOKEN') ?: '';
$zoneId = getenv('CF_ZONE_ID') ?: '';
$accountId = getenv('CF_ACCOUNT_ID') ?: '';
$zoneName = getenv('CF_ZONE_NAME') ?: '';

if ($token === '' || $zoneId === '' || $accountId === '' || $zoneName === '') {
    fwrite(STDERR, "Missing CF_API_TOKEN / CF_ZONE_ID / CF_ACCOUNT_ID / CF_ZONE_NAME\n");
    exit(2);
}

$service = new CloudflareEnhanceService([
    'apikey' => $token,
    'auth' => '1',
    'account_id' => $accountId,
]);

$summary = [
    'account_id' => $service->getDefaultAccountId(),
    'custom_hostnames' => null,
    'fallback_origin' => null,
    'tunnel' => null,
    'cleanup' => [],
];

$prefix = 'codex-php-smoke-' . time();
$tunnelName = $prefix;
$publicHostname = $prefix . '.' . $zoneName;
$hostnameRoute = 'internal-' . $prefix . '.' . $zoneName;
$customHostname = 'saas-' . $prefix . '.' . $zoneName;
$fallbackOrigin = 'origin-' . $prefix . '.' . $zoneName;
$cidr = '10.234.56.0/24';

$tunnel = null;
$cidrRoute = null;
$hostnameRouteRow = null;
$customHostnameRow = null;
$originalFallbackOrigin = null;

try {
    try {
        $before = $service->listCustomHostnames($zoneId);
        $summary['custom_hostnames_before'] = count($before);
        $customHostnameRow = $service->createCustomHostname($zoneId, $customHostname, null);
        $summary['custom_hostnames'] = [
            'ok' => true,
            'created' => [
                'id' => $customHostnameRow['id'] ?? '',
                'hostname' => $customHostnameRow['hostname'] ?? '',
                'ssl_status' => $customHostnameRow['ssl']['status'] ?? '',
                'ownership_status' => $customHostnameRow['ownership_verification']['http']['status']
                    ?? $customHostnameRow['ownership_verification']['txt']['status']
                    ?? '',
            ],
            'after_count' => count($service->listCustomHostnames($zoneId)),
        ];
    } catch (Throwable $e) {
        $summary['custom_hostnames'] = [
            'ok' => false,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
        ];
    }

    try {
        $originalFallbackOrigin = $service->getFallbackOrigin($zoneId);
        $updatedFallbackOrigin = $service->updateFallbackOrigin($zoneId, $fallbackOrigin);
        $summary['fallback_origin'] = [
            'ok' => true,
            'before' => $originalFallbackOrigin,
            'after' => $updatedFallbackOrigin,
        ];
    } catch (Throwable $e) {
        $summary['fallback_origin'] = [
            'ok' => false,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
        ];
    }

    $tunnel = $service->createTunnel($accountId, $tunnelName);
    $tunnelId = (string)($tunnel['id'] ?? '');
    $summary['tunnel'] = [
        'id' => $tunnelId,
        'name' => $tunnel['name'] ?? '',
        'status' => $tunnel['status'] ?? '',
        'token_prefix' => substr($service->getTunnelToken($accountId, $tunnelId), 0, 24),
        'initial_config' => $service->getTunnelConfig($accountId, $tunnelId),
    ];

    $service->updateTunnelConfig($accountId, $tunnelId, [
        'ingress' => [
            [
                'hostname' => $publicHostname,
                'service' => 'http://127.0.0.1:8080',
            ],
            [
                'service' => 'http_status:404',
            ],
        ],
    ]);

    $summary['tunnel']['updated_config'] = $service->getTunnelConfig($accountId, $tunnelId);
    $summary['tunnel']['dns_sync'] = $service->upsertTunnelCnameRecord($zoneId, $publicHostname, $tunnelId);

    $cidrRoute = $service->createCidrRoute($accountId, $tunnelId, $cidr, 'php smoke');
    $hostnameRouteRow = $service->createHostnameRoute($accountId, $tunnelId, $hostnameRoute, 'php smoke');

    $summary['tunnel']['cidr_routes'] = $service->listCidrRoutes($accountId, $tunnelId);
    $summary['tunnel']['hostname_routes'] = $service->listHostnameRoutes($accountId, $tunnelId);
} finally {
    if ($customHostnameRow && !empty($customHostnameRow['id'])) {
        try {
            $service->deleteCustomHostname($zoneId, (string)$customHostnameRow['id']);
            $summary['cleanup']['custom_hostname'] = true;
        } catch (Throwable $e) {
            $summary['cleanup']['custom_hostname'] = $e->getMessage();
        }
    }

    if ($summary['fallback_origin']['ok'] ?? false) {
        try {
            if ($originalFallbackOrigin !== null && $originalFallbackOrigin !== '') {
                $service->updateFallbackOrigin($zoneId, $originalFallbackOrigin);
            } else {
                $service->deleteFallbackOrigin($zoneId);
            }
            $summary['cleanup']['fallback_origin'] = true;
        } catch (Throwable $e) {
            $summary['cleanup']['fallback_origin'] = $e->getMessage();
        }
    }

    if ($tunnel && !empty($tunnel['id'])) {
        $tunnelId = (string)$tunnel['id'];
        try {
            $service->deleteTunnelCnameRecordIfMatch($zoneId, $publicHostname, $tunnelId);
            $summary['cleanup']['dns'] = true;
        } catch (Throwable $e) {
            $summary['cleanup']['dns'] = $e->getMessage();
        }

        if ($cidrRoute && !empty($cidrRoute['id'])) {
            try {
                $service->deleteCidrRoute($accountId, (string)$cidrRoute['id']);
                $summary['cleanup']['cidr'] = true;
            } catch (Throwable $e) {
                $summary['cleanup']['cidr'] = $e->getMessage();
            }
        }

        if ($hostnameRouteRow && !empty($hostnameRouteRow['id'])) {
            try {
                $service->deleteHostnameRoute($accountId, (string)$hostnameRouteRow['id']);
                $summary['cleanup']['hostname_route'] = true;
            } catch (Throwable $e) {
                $summary['cleanup']['hostname_route'] = $e->getMessage();
            }
        }

        try {
            $service->updateTunnelConfig($accountId, $tunnelId, [
                'ingress' => [
                    ['service' => 'http_status:404'],
                ],
            ]);
            $summary['cleanup']['config'] = true;
        } catch (Throwable $e) {
            $summary['cleanup']['config'] = $e->getMessage();
        }

        try {
            $service->deleteTunnel($accountId, $tunnelId);
            $summary['cleanup']['tunnel'] = true;
        } catch (Throwable $e) {
            $summary['cleanup']['tunnel'] = $e->getMessage();
        }
    }
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
