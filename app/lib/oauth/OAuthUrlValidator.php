<?php

namespace app\lib\oauth;

class OAuthUrlValidator
{
    public function isSafeHttpsUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        return $this->resolvePublicIps($host) !== [];
    }

    public function resolvePublicIps(string $host): array
    {
        $host = trim($host, '[]');
        if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
            return [];
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPrivateIp($host) ? [] : [$host];
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA);
        if (!$records) {
            return [];
        }
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null || $this->isPrivateIp($ip)) {
                return [];
            }
            $ips[] = $ip;
        }
        return array_values(array_unique($ips));
    }

    public function isPrivateHost(string $host): bool
    {
        return $this->resolvePublicIps($host) === [];
    }

    private function isPrivateIp(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
