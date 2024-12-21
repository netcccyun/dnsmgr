<?php

// 应用公共文件
use app\model\Config;
use think\facade\Db;
use GuzzleHttp\Client;
use think\facade\Request;
use Pdp\Rules;
use Pdp\Domain;

use GuzzleHttp\Exception\RequestException;

function get_curl(string $url, $post = 0, $referer = 0, $cookie = 0, $header = 0, $ua = 0, $nobody = 0)
{
    $options = [];
    if (is_array($header) && !empty($header)) {
        $options['headers'] = $header;
    }
    if ($ua) {
        $options['headers']['user-agent'] = $ua;
    } else {
        $options['headers']['user-agent'] = 'Mozilla/5.0 (Linux; U; Android 4.0.4; es-mx; HTC_One_X Build/IMM76D) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0';
    }
    if (is_string($referer) && $referer != '') {
        $options['headers']['referer'] = $referer;
    }
    if (is_string($cookie) && $cookie != '') {
        $options['headers']['cookie'] = $cookie;
    }
    $client = new Client($options);
    if (!is_array($post)) {
        $response = $client->get($url);
    } else {
        $response = $client->post($url, [
            'form_params' => $post,
            'headers' => $header,
            'verify' => false,
        ]);
    }

    if ($nobody) {
        $ret = $response->getHeaders();
    } else {
        $ret = $response->getBody()->getContents();
    }
    return $ret;
}

function real_ip($type = 0)
{
    $ip = $_SERVER['REMOTE_ADDR'];
    if ($type <= 0 && isset($_SERVER['HTTP_X_FORWARDED_FOR']) && preg_match_all('#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#s', $_SERVER['HTTP_X_FORWARDED_FOR'], $matches)) {
        foreach ($matches[0] as $xip) {
            if (filter_var($xip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ip = $xip;
                break;
            }
        }
    } elseif ($type <= 0 && isset($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif ($type <= 1 && isset($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif ($type <= 1 && isset($_SERVER['HTTP_X_REAL_IP']) && filter_var($_SERVER['HTTP_X_REAL_IP'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    return $ip;
}

function strexists($string, $find)
{
    return !(strpos($string, $find) === false);
}

function dstrpos($string, $arr)
{
    if (empty($string)) {
        return false;
    }
    foreach ((array)$arr as $v) {
        if (strpos($string, $v) !== false) {
            return true;
        }
    }
    return false;
}

function checkmobile()
{
    $useragent = strtolower($_SERVER['HTTP_USER_AGENT']);
    $ualist = ['android', 'midp', 'nokia', 'mobile', 'iphone', 'ipod', 'blackberry', 'windows phone'];
    if ((dstrpos($useragent, $ualist) || strexists($_SERVER['HTTP_ACCEPT'], "VND.WAP") || strexists($_SERVER['HTTP_VIA'], "wap"))) {
        return true;
    } else {
        return false;
    }
}

function authcode($string, $operation = 'DECODE', $key = '', $expiry = 0)
{
    $ckey_length = 4;
    $key = md5($key);
    $keya = md5(substr($key, 0, 16));
    $keyb = md5(substr($key, 16, 16));
    $keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), -$ckey_length)) : '';
    $cryptkey = $keya.md5($keya.$keyc);
    $key_length = strlen($cryptkey);
    $string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0).substr(md5($string.$keyb), 0, 16).$string;
    $string_length = strlen($string);
    $result = '';
    $box = range(0, 255);
    $rndkey = [];
    for ($i = 0; $i <= 255; $i++) {
        $rndkey[$i] = ord($cryptkey[$i % $key_length]);
    }
    for ($j = $i = 0; $i < 256; $i++) {
        $j = ($j + $box[$i] + $rndkey[$i]) % 256;
        $tmp = $box[$i];
        $box[$i] = $box[$j];
        $box[$j] = $tmp;
    }
    for ($a = $j = $i = 0; $i < $string_length; $i++) {
        $a = ($a + 1) % 256;
        $j = ($j + $box[$a]) % 256;
        $tmp = $box[$a];
        $box[$a] = $box[$j];
        $box[$j] = $tmp;
        $result .= chr(ord($string[$i]) ^ ($box[($box[$a] + $box[$j]) % 256]));
    }
    if ($operation == 'DECODE') {
        if (((int)substr($result, 0, 10) == 0 || (int)substr($result, 0, 10) - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26).$keyb), 0, 16)) {
            return substr($result, 26);
        } else {
            return '';
        }
    } else {
        return $keyc.base64_encode($result);
    }
}

function random($length, $numeric = 0)
{
    $seed = base_convert(md5(microtime().$_SERVER['DOCUMENT_ROOT']), 16, $numeric ? 10 : 35);
    $seed = $numeric ? (str_replace('0', '', $seed).'012340567890') : ($seed.'zZ'.strtoupper($seed));
    $hash = '';
    $max = strlen($seed) - 1;
    for ($i = 0; $i < $length; $i++) {
        $hash .= $seed[mt_rand(0, $max)];
    }
    return $hash;
}

function checkDomain($domain)
{
    if (empty($domain) || !preg_match('/^[-$a-z0-9_*.]{2,512}$/i', $domain) || (stripos($domain, '.') === false) || str_ends_with($domain, '.') || str_starts_with($domain, '.') || str_starts_with($domain, '*') && substr($domain, 1, 1) != '.' || substr_count($domain, '*') > 1 || strpos($domain, '*') > 0 || strlen($domain) < 4) {
        return false;
    }
    return true;
}

function getSubstr($str, $leftStr, $rightStr)
{
    $left = strpos($str, $leftStr);
    $start = $left + strlen($leftStr);
    $right = strpos($str, $rightStr, $start);
    if ($left < 0) {
        return '';
    }
    if ($right > 0) {
        return substr($str, $start, $right - $start);
    } else {
        return substr($str, $start);
    }
}

function checkRefererHost()
{
    if (!Request::header('referer')) {
        return false;
    }
    $url_arr = parse_url(Request::header('referer'));
    $http_host = Request::header('host');
    if (strpos($http_host, ':')) {
        $http_host = substr($http_host, 0, strpos($http_host, ':'));
    }
    return $url_arr['host'] === $http_host;
}

function checkIfActive($string)
{
    $array = explode(',', $string);
    $action = Request::action();
    if (in_array($action, $array)) {
        return 'active';
    } else {
        return null;
    }
}

function getSid()
{
    return md5(uniqid(mt_rand(), true) . microtime());
}
function getMd5Pwd($pwd, $salt = null)
{
    return md5(md5($pwd) . md5('1277180438'.$salt));
}

function isNullOrEmpty($str)
{
    return $str === null || $str === '';
}

function checkPermission($type, $domain = null)
{
    $user = Request()->user;
    if (empty($user)) {
        return false;
    }
    if ($user['level'] == 2) {
        return true;
    }
    if ($type == 1 && $user['level'] == 1 || $type == 0 && $user['level'] >= 0) {
        if ($domain == null) {
            return true;
        }
        if (in_array($domain, $user['permission'])) {
            return true;
        }
    }
    return false;
}

function getAdminSkin()
{
    $skin = cookie('admin_skin');
    if (empty($skin)) {
        $skin = config_get('admin_skin');
    }
    if (empty($skin)) {
        $skin = 'skin-black-blue';
    }
    return $skin;
}

function config_get($key, $default = null, $force = false)
{
    if ($force) {
        $value = Config::where('key', $key)->value('value');
    } else {
        $value = config("sys.$key");
    }
    return $value ?: $default;
}

function config_set($key, $value)
{
    $res = Db::name('config')->replace()->insert(['key' => $key, 'value' => $value]);
    return $res !== false;
}

function getMillisecond()
{
    [$s1, $s2] = explode(' ', microtime());
    return (int)sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
}

function getDnsType($value)
{
    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return 'A';
    } elseif (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return 'AAAA';
    } else {
        return 'CNAME';
    }
}

function convert_second($s)
{
    $m = floor($s / 60);
    if ($m == 0) {
        return $s.'秒';
    } else {
        $s = $s % 60;
        $h = floor($m / 60);
        if ($h == 0) {
            return $m.'分钟'.$s.'秒';
        } else {
            $m = $m % 60;
            return $h.'小时'.$m.'分钟'.$s.'秒';
        }
    }
}

function getMainDomain($host)
{
    $publicSuffixList = Rules::fromPath(app()->getBasePath() . 'data' . DIRECTORY_SEPARATOR . 'public_suffix_list.dat');
    if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
    $domain_check = Domain::fromIDNA2008($host);
    $result = $publicSuffixList->resolve($domain_check);
    $domain_parse = $result->suffix()->toString();
    return $domain_parse ?: $host;
}

function check_proxy($url, $proxy_server, $proxy_port, $type, $proxy_user, $proxy_pwd)
{
    if ($type == 'https') {
        $proxy_type = CURLPROXY_HTTPS;
    } elseif ($type == 'sock4') {
        $proxy_type = CURLPROXY_SOCKS4;
    } elseif ($type == 'sock5') {
        $proxy_type = CURLPROXY_SOCKS5;
    } else {
        $proxy_type = CURLPROXY_HTTP;
    }
    $options = [
        CURLOPT_PROXYTYPE => $proxy_type,
        CURLOPT_PROXYAUTH => CURLAUTH_BASIC,
        CURLOPT_PROXY => $proxy_server,
        CURLOPT_PROXYUSERPWD => !empty($proxy_user) && !empty($proxy_pwd) ? $proxy_user . ':' . $proxy_pwd : '',
        CURLOPT_PROXYPORT => $proxy_port,
    ];
    $client = new Client([
        'curl' => $options,
        'timeout' => 3,
        'verify' => false,
        'headers' => [
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36',
        ],
    ]);
    try {
        $response = $client->request('GET', $url);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        throw new Exception($e->getMessage());
    }
    $httpCode = $response->getStatusCode();
    if ($httpCode >= 200 && $httpCode < 400) {
        return true;
    } else {
        throw new Exception('HTTP状态码异常：' . $httpCode);
    }
}



function curl_client($url, $data = null, $referer = null, $cookie = null, $headers = null, $proxy = false, $method = 'GET', $timeout = 5): array
{
    $client = new Client([
        'timeout' => $timeout,
        'verify' => false, // 禁用 SSL 验证
    ]);

    $options = [
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.6261.95 Safari/537.36',
        ],
        'curl' => [], // 用于设置额外的 cURL 配置
    ];

    if ($headers) {
        $options['headers'] = array_merge($options['headers'], $headers);
    }

    if ($data) {
        if (strtoupper($method) === 'POST') {
            $options['form_params'] = $data; // 表单提交
        } else {
            $options['body'] = $data; // 其他方法用 body 提交
        }
    }

    if ($cookie) {
        $options['headers']['Cookie'] = $cookie;
    }

    if ($referer) {
        $options['headers']['Referer'] = $referer;
    }

    if ($proxy) {
        $proxy_server = config_get('proxy_server');
        $proxy_port = intval(config_get('proxy_port'));
        $proxy_user = config_get('proxy_user');
        $proxy_pwd = config_get('proxy_pwd');
        $proxy_type = strtolower(config_get('proxy_type'));

        $proxy_url = "$proxy_server:$proxy_port";

        $options['curl'][CURLOPT_PROXY] = $proxy_url;

        // 设置代理类型
        if ($proxy_type === 'socks4') {
            $options['curl'][CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS4;
        } elseif ($proxy_type === 'socks5') {
            $options['curl'][CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
        } else {
            $options['curl'][CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
        }

        // 设置代理认证
        if ($proxy_user && $proxy_pwd) {
            $options['curl'][CURLOPT_PROXYUSERPWD] = "$proxy_user:$proxy_pwd";
        }
    }

    try {
        $response = $client->request($method, $url, $options);

        return [
            'code' => $response->getStatusCode(),
            'redirect_url' => $response->getHeaderLine('Location'),
            'header' => $response->getHeaders(),
            'body' => $response->getBody()->getContents(),
        ];
    } catch (RequestException $e) {
        if ($e->hasResponse()) {
            return [
                'code' => $e->getResponse()->getStatusCode(),
                'redirect_url' => $e->getResponse()->getHeaderLine('Location'),
                'header' => $e->getResponse()->getHeaders(),
                'body' => $e->getResponse()->getBody()->getContents(),
            ];
        }
        throw new Exception('HttpClient error: ' . $e->getMessage());
    }
}

