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
        'User-Agent: Codex-Hostname-Smoke/1.0',
    ];
    $normalizedHeaders = [];
    foreach ((array)$headers as $key => $value) {
        $normalizedHeaders[strtolower((string)$key)] = (string)$value;
        $headerLines[] = $key . ': ' . $value;
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
$zoneName = getenv('CF_ZONE_NAME') ?: '';

if ($token === '' || $zoneId === '' || $zoneName === '') {
    fwrite(STDERR, "Missing CF_API_TOKEN / CF_ZONE_ID / CF_ZONE_NAME\n");
    exit(2);
}

$service = new CloudflareEnhanceService([
    'apikey' => $token,
    'auth' => '1',
]);

$prefix = 'codex-edit-' . time();
$hostname = $prefix . '.' . $zoneName;
$origin = 'origin-' . $prefix . '.' . $zoneName;

$summary = [
    'created' => null,
    'updated' => null,
    'refreshed' => null,
    'cleanup' => null,
];

$created = null;

try {
    $created = $service->createCustomHostname($zoneId, $hostname, null);
    $hostnameId = (string)($created['id'] ?? '');
    $summary['created'] = [
        'id' => $hostnameId,
        'hostname' => $created['hostname'] ?? '',
        'ownership_txt_name' => $created['ownership_verification']['name'] ?? '',
        'ownership_txt_value' => $created['ownership_verification']['value'] ?? '',
        'ownership_http_url' => $created['ownership_verification_http']['http_url'] ?? '',
        'ownership_http_body' => $created['ownership_verification_http']['http_body'] ?? '',
    ];

    $updated = $service->updateCustomHostname($zoneId, $hostnameId, [
        'custom_origin_server' => $origin,
        'ssl' => [
            'method' => 'http',
            'type' => 'dv',
        ],
    ]);
    $summary['updated'] = [
        'custom_origin_server' => $updated['custom_origin_server'] ?? '',
        'ssl_status' => $updated['ssl']['status'] ?? '',
        'validation_record_count' => count($updated['ssl']['validation_records'] ?? []),
        'first_http_url' => $updated['ssl']['validation_records'][0]['http_url'] ?? ($updated['ssl']['http_url'] ?? ''),
        'first_http_body' => $updated['ssl']['validation_records'][0]['http_body'] ?? ($updated['ssl']['http_body'] ?? ''),
    ];

    $current = $service->getCustomHostname($zoneId, $hostnameId);
    $refreshed = $service->updateCustomHostname($zoneId, $hostnameId, [
        'custom_origin_server' => trim((string)($current['custom_origin_server'] ?? '')) !== '' ? $current['custom_origin_server'] : null,
        'ssl' => [
            'method' => $current['ssl']['method'] ?? 'http',
            'type' => $current['ssl']['type'] ?? 'dv',
        ],
    ]);
    $summary['refreshed'] = [
        'ssl_status' => $refreshed['ssl']['status'] ?? '',
        'validation_record_count' => count($refreshed['ssl']['validation_records'] ?? []),
        'ownership_txt_name' => $refreshed['ownership_verification']['name'] ?? '',
        'ownership_http_url' => $refreshed['ownership_verification_http']['http_url'] ?? '',
    ];
} finally {
    if ($created && !empty($created['id'])) {
        try {
            $service->deleteCustomHostname($zoneId, (string)$created['id']);
            $summary['cleanup'] = true;
        } catch (Throwable $e) {
            $summary['cleanup'] = $e->getMessage();
        }
    }
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
