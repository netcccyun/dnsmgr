<?php

namespace app\utils;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CheckUtils
{
    public static function curl($url, $timeout, $ip = null, $proxy = false)
    {
        $status = true;
        $errmsg = null;
        $start = microtime(true);

        $urlarr = parse_url($url);
        if (!$urlarr) {
            return ['status' => false, 'errmsg' => 'Invalid URL', 'usetime' => 0];
        }
        if (str_starts_with($urlarr['host'], '[') && str_ends_with($urlarr['host'], ']')) {
            $urlarr['host'] = substr($urlarr['host'], 1, -1);
        }
        if (!empty($ip) && !filter_var($urlarr['host'], FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = gethostbyname($ip);
            }
            if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $port = $urlarr['port'] ?? ($urlarr['scheme'] == 'https' ? 443 : 80);
                $resolve = $urlarr['host'] . ':' . $port . ':' . $ip;
            }
        }

        $options = [
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'verify' => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
            ],
            'http_errors' => false // 不抛出异常
        ];
        // 处理解析
        if (!empty($resolve)) {
            $options['curl'] = [
                CURLOPT_DNS_USE_GLOBAL_CACHE => false,
                CURLOPT_RESOLVE => [$resolve]
            ];
        }
        // 处理代理
        if ($proxy) {
            $proxy_server = config_get('proxy_server');
            $proxy_port = intval(config_get('proxy_port'));
            $proxy_userpwd = config_get('proxy_user').':'.config_get('proxy_pwd');
            $proxy_type = config_get('proxy_type');

            if (!empty($proxy_server) && !empty($proxy_port)) {
                match ($proxy_type) {
                    'https' => $proxy_string = 'https://',
                    'sock4' => $proxy_string = 'socks4://',
                    'sock5' => $proxy_string = 'socks5://',
                    'sock5h' => $proxy_string = 'socks5h://',
                    default => $proxy_string = 'http://',
                };

                if ($proxy_userpwd != ':') {
                    $proxy_string .= $proxy_userpwd . '@';
                }

                $proxy_string .= $proxy_server . ':' . $proxy_port;
                $options['proxy'] = $proxy_string;
            }
        }

        try {
            $client = new Client();
            $response = $client->request('GET', $url, $options);
            $httpcode = $response->getStatusCode();

            if ($httpcode < 200 || $httpcode >= 400) {
                $status = false;
                $errmsg = 'http_code=' . $httpcode;
            }
        } catch (GuzzleException $e) {
            $status = false;
            $errmsg = $e->getMessage();
            if (preg_match('/^cURL error \d+: /', $errmsg)) {
                $errmsg = preg_replace('/^cURL error \d+: /', '', $errmsg);
            }
            $errmsg = str_replace(' (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)', '', $errmsg);
            if (strlen($errmsg) > 100) {
                $errmsg = substr($errmsg, 0, 97) . '...';
            }
        }

        $usetime = round((microtime(true) - $start) * 1000);
        return ['status' => $status, 'errmsg' => $errmsg, 'usetime' => $usetime];
    }

    public static function tcp($target, $ip, $port, $timeout)
    {
        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) $target = $ip;
        if (str_ends_with($target, '.')) $target = substr($target, 0, -1);
        if (!filter_var($target, FILTER_VALIDATE_IP) && checkDomain($target)) {
            $target = gethostbyname($target);
            if (!$target) return ['status' => false, 'errmsg' => 'DNS resolve failed', 'usetime' => 0];
        }
        if (filter_var($target, FILTER_VALIDATE_IP) && str_contains($target, ':')) {
            $target = '['.$target.']';
        }
        $starttime = getMillisecond();
        $fp = @fsockopen($target, $port, $errCode, $errStr, $timeout);
        if ($fp) {
            $status = true;
            fclose($fp);
        } else {
            $status = false;
        }
        $endtime = getMillisecond();
        $usetime = $endtime - $starttime;
        return ['status' => $status, 'errmsg' => $errStr, 'usetime' => $usetime];
    }

    public static function ping($target, $ip)
    {
        if (!function_exists('exec')) return ['status' => false, 'errmsg' => 'exec函数不可用', 'usetime' => 0];
        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) $target = $ip;
        if (str_ends_with($target, '.')) $target = substr($target, 0, -1);
        if (!filter_var($target, FILTER_VALIDATE_IP) && checkDomain($target)) {
            $target = gethostbyname($target);
            if (!$target) return ['status' => false, 'errmsg' => 'DNS resolve failed', 'usetime' => 0];
        }
        if (!filter_var($target, FILTER_VALIDATE_IP)) {
            return ['status' => false, 'errmsg' => 'Invalid IP address', 'usetime' => 0];
        }
        $timeout = 1;
        exec('ping -c 1 -w '.$timeout.' '.$target, $output, $return_var);
        $usetime = !empty($output[1]) ? round(getSubstr($output[1], 'time=', ' ms')) : 0;
        $errmsg = null;
        if ($return_var !== 0) {
            $usetime = $usetime == 0 ? $timeout * 1000 : $usetime;
            $errmsg = 'ping timeout';
        }
        return ['status' => $return_var === 0, 'errmsg' => $errmsg, 'usetime' => $usetime];
    }
}
