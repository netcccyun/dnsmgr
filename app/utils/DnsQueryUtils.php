<?php

namespace app\utils;

use Exception;

class DnsQueryUtils
{
    private static $doh_servers = ['https://dns.alidns.com/resolve', 'https://doh.pub/resolve', 'https://doh.360.cn/resolve'];

    public static function get_dns_records($domain, $type)
    {
        $dns_type = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'TXT' => DNS_TXT];
        if (!array_key_exists($type, $dns_type)) return false;
        try{
            $list = dns_get_record($domain, $dns_type[$type]);
        }catch(Exception $e){
            return false;
        }
        if (!$list || empty($list)) return false;
        $result = [];
        foreach ($list as $row) {
            if ($row['type'] == 'A') {
                $result[] = $row['ip'];
            } elseif ($row['type'] == 'AAAA') {
                $result[] = $row['ipv6'];
            } elseif ($row['type'] == 'CNAME') {
                $result[] = $row['target'];
            } elseif ($row['type'] == 'MX') {
                $result[] = $row['target'];
            } elseif ($row['type'] == 'TXT') {
                $result[] = $row['txt'];
            }
        }
        return $result;
    }

    public static function query_dns_doh($domain, $type)
    {
        $dns_type = ['A' => 1, 'AAAA' => 28, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'SOA' => 6, 'NS' => 2, 'PTR' => 12, 'SRV' => 33, 'CAA' => 257];
        if (!array_key_exists($type, $dns_type)) return false;
        $id = array_rand(self::$doh_servers);
        $url = self::$doh_servers[$id].'?name='.urlencode($domain).'&type='.$dns_type[$type];
        $data = get_curl($url);
        $arr = json_decode($data, true);
        if (!$arr) {
            unset(self::$doh_servers[$id]);
            $id = array_rand(self::$doh_servers);
            $url = self::$doh_servers[$id].'?name='.urlencode($domain).'&type='.$dns_type[$type];
            $data = get_curl($url);
            $arr = json_decode($data, true);
            if (!$arr) return false;
        }
        $result = [];
        if (isset($arr['Answer'])) {
            foreach ($arr['Answer'] as $row) {
                $value = $row['data'];
                if ($row['type'] == 5) $value = trim($value, '.');
                if ($row['type'] == 16) $value = trim($value, '"');
                $result[] = $value;
            }
        }
        return $result;
    }
}
