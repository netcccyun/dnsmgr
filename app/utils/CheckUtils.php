<?php

namespace app\utils;

class CheckUtils
{
    public static function curl($url, $timeout, $ip = null, $proxy = false)
    {
        $status = true;
        $errmsg = null;
        $urlarr = parse_url($url);
        if (!empty($ip) && !filter_var($urlarr['host'], FILTER_VALIDATE_IP)) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = gethostbyname($ip);
            }
            if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $port = isset($urlarr['port']) ? $urlarr['port'] : ($urlarr['scheme'] == 'https' ? 443 : 80);
                $resolve = $urlarr['host'] . ':' . $port . ':' . $ip;
            }
        }
        $ch = curl_init();
        if ($proxy) {
            $proxy_server = config_get('proxy_server');
            $proxy_port = intval(config_get('proxy_port'));
            $proxy_userpwd = config_get('proxy_user').':'.config_get('proxy_pwd');
            $proxy_type = config_get('proxy_type');
            if ($proxy_type == 'https') {
                $proxy_type = CURLPROXY_HTTPS;
            } elseif ($proxy_type == 'sock4') {
                $proxy_type = CURLPROXY_SOCKS4;
            } elseif ($proxy_type == 'sock5') {
                $proxy_type = CURLPROXY_SOCKS5;
            } else {
                $proxy_type = CURLPROXY_HTTP;
            }
            curl_setopt($ch, CURLOPT_PROXYAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_PROXY, $proxy_server);
            curl_setopt($ch, CURLOPT_PROXYPORT, $proxy_port);
            if ($proxy_userpwd != ':') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_userpwd);
            }
            curl_setopt($ch, CURLOPT_PROXYTYPE, $proxy_type);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $httpheader[] = "Accept: */*";
        $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
        $httpheader[] = "Connection: close";
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if (!empty($resolve)) {
            curl_setopt($ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
            curl_setopt($ch, CURLOPT_RESOLVE, [$resolve]);
        }
        curl_exec($ch);
        $errno = curl_errno($ch);
        if ($errno) {
            $status = false;
            $errmsg = curl_error($ch);
        }
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status && ($httpcode < 200 || $httpcode >= 400)) {
            $status = false;
            $errmsg = 'http_code='.$httpcode;
        }
        $usetime = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
        curl_close($ch);
        return ['status' => $status, 'errmsg' => $errmsg, 'usetime' => $usetime];
    }

    public static function tcp($target, $ip, $port, $timeout)
    {
        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) $target = $ip;
        if (substr($target, -1) == '.') $target = substr($target, 0, -1);
        if (!filter_var($target, FILTER_VALIDATE_IP) && checkDomain($target)) {
            $target = gethostbyname($target);
            if (!$target) return ['status' => false, 'errmsg' => 'DNS resolve failed', 'usetime' => 0];
        }
        if (filter_var($target, FILTER_VALIDATE_IP) && strpos($target, ':') !== false) {
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
        if (substr($target, -1) == '.') $target = substr($target, 0, -1);
        if (!filter_var($target, FILTER_VALIDATE_IP) && checkDomain($target)) {
            $target = gethostbyname($target);
            if (!$target) return ['status' => false, 'errmsg' => 'DNS resolve failed', 'usetime' => 0];
        }
        if (!filter_var($target, FILTER_VALIDATE_IP)) {
            return ['status' => false, 'errmsg' => 'Invalid IP address', 'usetime' => 0];
        }
        $timeout = 1;
        exec('ping -c 1 -w '.$timeout.' '.$target.'', $output, $return_var);
        $usetime = !empty($output[1]) ? round(getSubstr($output[1], 'time=', ' ms')) : 0;
        $errmsg = null;
        if ($return_var !== 0) {
            $usetime = $usetime == 0 ? $timeout * 1000 : $usetime;
            $errmsg = 'ping timeout';
        }
        return ['status' => $return_var === 0, 'errmsg' => $errmsg, 'usetime' => $usetime];
    }
}
