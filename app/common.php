<?php
// 应用公共文件
use think\facade\Db;
use think\facade\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

function get_curl($url, $post = 0, $referer = 0, $cookie = 0, $ua = 0, $nobody = 0, $addheader = [])
{
    $options = [
        'timeout' => 10,
        'verify' => false,
        'headers' => [
            'User-Agent' => $ua ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
        ],
        'http_errors' => false // 不抛出异常
    ];

    $options['headers'] = array_merge($options['headers'], $addheader);
    if ($referer) {
        $options['headers']['Referer'] = $referer;
    }
    if ($cookie) {
        $options['headers']['Cookie'] = $cookie;
    }

    $method = 'GET';
    if ($post) {
        $method = 'POST';
        if (!isset($options['headers']['Content-Type'])) {
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        if (is_array($post)) {
            $options['form_params'] = $post;
        } else {
            $options['body'] = $post;
        }
    }

    try {
        $client = new Client();
        $response = $client->request($method, $url, $options);

        if ($nobody) {
            return '';
        }

        return $response->getBody()->getContents();
    } catch (GuzzleException $e) {
        return '';
    }
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
    return !(strpos($string, $find) === FALSE);
}

function dstrpos($string, $arr)
{
    if (empty($string)) return false;
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
    $ualist = array('android', 'midp', 'nokia', 'mobile', 'iphone', 'ipod', 'blackberry', 'windows phone');
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
    $rndkey = array();
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
    if (empty($domain) || !preg_match('/^[-$a-z0-9_*.]{2,512}$/i', $domain) || (stripos($domain, '.') === false) || substr($domain, -1) == '.' || substr($domain, 0, 1) == '.' || substr($domain, 0, 1) == '*' && substr($domain, 1, 1) != '.' || substr_count($domain, '*') > 1 || strpos($domain, '*') > 0 || strlen($domain) < 4) return false;
    return true;
}

function getSubstr($str, $leftStr, $rightStr)
{
    $left = strpos($str, $leftStr);
    $start = $left + strlen($leftStr);
    $right = strpos($str, $rightStr, $start);
    if ($left < 0) return '';
    if ($right > 0) {
        return substr($str, $start, $right - $start);
    } else {
        return substr($str, $start);
    }
}

function arrays_are_equal($array1, $array2)
{
    return empty(array_diff($array1, $array2)) && empty(array_diff($array2, $array1));
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
        $value = Db::name('config')->where('key', $key)->value('value');
    } else {
        $value = config('sys.' . $key);
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
    list($s1, $s2) = explode(' ', microtime());
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
    if (filter_var($host, FILTER_VALIDATE_IP)) return $host;
    $domains = config('temp.domains');
    if (!$domains) {
        $domains = Db::name('domain')->column('name');
        config(['domains'=>$domains], 'temp');
    }
    foreach ($domains as $domain) {
        if (str_ends_with($host, $domain)) {
            return $domain;
        }
    }
    $domain_root = file_get_contents(app()->getBasePath() . 'data' . DIRECTORY_SEPARATOR . 'domain_root.txt');
    $domain_root = explode("\r\n", $domain_root);
    $data = explode('.', $host);
    $co_ta = count($data);
    if ($co_ta <= 2) return $host;
    $domain_name = $data[$co_ta - 2] . '.' . $data[$co_ta - 1];
    if (in_array($domain_name, $domain_root) && $co_ta > 2) {
        $domain_name = $data[$co_ta - 3] . '.' . $domain_name;
    }
    return $domain_name;
}

function check_proxy($url, $proxy_server, $proxy_port, $type, $proxy_user, $proxy_pwd)
{
    match ($type) {
        'https' => $proxy_string = 'https://',
        'sock4' => $proxy_string = 'socks4://',
        'sock5' => $proxy_string = 'socks5://',
        'sock5h' => $proxy_string = 'socks5h://',
        default => $proxy_string = 'http://',
    };

    if (!empty($proxy_user) && !empty($proxy_pwd)) {
        $proxy_string .= $proxy_user . ':' . $proxy_pwd . '@';
    }

    $proxy_string .= $proxy_server . ':' . intval($proxy_port);
    $options = [
        'proxy' => $proxy_string,
        'timeout' => 3,
        'connect_timeout' => 3,
        'verify' => false,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
        ]
    ];

    try {
        $client = new Client();
        $response = $client->request('GET', $url, $options);
        $httpCode = $response->getStatusCode();

        if ($httpCode >= 200 && $httpCode < 400) {
            return true;
        } else {
            throw new Exception('HTTP状态码异常：' . $httpCode);
        }
    } catch (GuzzleException $e) {
        throw new Exception($e->getMessage());
    }
}

function clearDirectory($dir): bool
{
    // 确保路径是目录
    if (!is_dir($dir)) {
        return false;
    }

    // 打开目录
    $items = scandir($dir);
    foreach ($items as $item) {
        // 跳过 '.' 和 '..'
        if ($item == '.' || $item == '..') {
            continue;
        }

        // 完整路径
        $path = $dir . DIRECTORY_SEPARATOR . $item;

        // 如果是目录，递归删除其内容
        if (is_dir($path)) {
            clearDirectory($path);
            // 删除空目录
            rmdir($path);
        } else {
            // 删除文件
            unlink($path);
        }
    }
    return true;
}

/**
 * 发送 HTTP 请求
 *
 * @param string $url 请求URL
 * @param mixed $data 请求数据，可以是字符串或数组，数组将自动根据请求方法及传入的 Content-Type 头序列化
 * @param string|null $referer 请求的 Referer 头
 * @param array|null $cookie 请求的 Cookie 头
 * @param array|null $headers 其他自定义请求头
 * @param bool $proxy 是否使用代理
 * @param string|null $method 请求方法，默认为 GET 或 POST（如果存在请求数据）
 * @param int $timeout 请求超时时间，默认为 10 秒
 * @return array 包含 HTTP 状态码、重定向 URL、响应头和响应体的数组
 * @throws Exception
 */
function http_request($url, $data = null, $referer = null, $cookie = null, $headers = null, $proxy = false, $method = null, $timeout = 10): array
{
    $options = [
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
        'allow_redirects' => false,
        'verify' => false,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        ],
        'http_errors' => false // 不抛出异常
    ];

    // 默认请求方法
    if (!$method) {
        $method = $data ? 'POST' : 'GET';
    }
    // 处理头部
    if (is_array($headers)) {
        $options['headers'] = array_merge($options['headers'], $headers);
    }
    // 处理Cookie
    if ($cookie) {
        $options['headers']['Cookie'] = $cookie;
    }
    // 处理Referer
    if ($referer) {
        $options['headers']['Referer'] = $referer;
    }
    // 处理数据
    if ($data) {
        if ($method !== 'GET') {
            if (is_string($data)) {
                $options['body'] = $data;
                if (!isset($options['headers']['Content-Type'])) {
                    if (json_validate($data)) {
                        // json
                        $options['headers']['Content-Type'] = 'application/json';
                    } elseif (str_contains($data, '=') || str_contains($data, '&')) {
                        // 表单
                        $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                    }
                }
            } else if (is_array($data) || is_object($data)) {
                if (!isset($options['headers']['Content-Type'])) {
                    // 默认为表单
                    $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
                }
                if ($options['headers']['Content-Type'] == 'application/x-www-form-urlencoded') {
                    // 表单
                    $options['form_params'] = $data;
                } else if ($options['headers']['Content-Type'] == 'application/json') {
                    // json
                    $options['json'] = $data;
                } else {
                    // 其他
                    $options['body'] = http_build_query($data);
                }
            } else {
                $options['body'] = $data;
            }
        } else {
            // 兼容已经存在查询字符串的情况
            if (!str_contains($url, '?')) {
                $options['query'] = $data;
            }
        }
    }
    // 处理代理
    if ($proxy) {
        $proxy_server = config_get('proxy_server');
        $proxy_port = intval(config_get('proxy_port'));
        $proxy_userpwd = config_get('proxy_user').':'.config_get('proxy_pwd');
        $proxy_type = config_get('proxy_type');

        if (empty($proxy_server) || empty($proxy_port)) {
            throw new Exception('代理服务器或端口未配置');
        }

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

    try {
        $client = new Client();
        $response = $client->request($method, $url, $options);
        $code = $response->getStatusCode();

        // 取重定向URL
        $redirect_url = '';
        if ($code >= 300 && $code < 400) {
            $redirect_url = $response->getHeaderLine('Location');
        }

        return [
            'code' => $code,
            'redirect_url' => $redirect_url,
            'headers' => $response->getHeaders(),
            'body' => $response->getBody()->getContents()
        ];
    } catch (GuzzleException $e) {
        throw new Exception('请求失败: ' . $e->getMessage());
    }
}

function curl_set_proxy(&$ch)
{
    $proxy_server = config_get('proxy_server');
    $proxy_port = intval(config_get('proxy_port'));
    $proxy_userpwd = config_get('proxy_user') . ':' . config_get('proxy_pwd');
    $proxy_type = config_get('proxy_type');
    if (empty($proxy_server) || empty($proxy_port)) {
        return;
    }
    if ($proxy_type == 'https') {
        $proxy_type = CURLPROXY_HTTPS;
    } elseif ($proxy_type == 'sock4') {
        $proxy_type = CURLPROXY_SOCKS4;
    } elseif ($proxy_type == 'sock5') {
        $proxy_type = CURLPROXY_SOCKS5;
    } elseif ($proxy_type == 'sock5h') {
        $proxy_type = CURLPROXY_SOCKS5_HOSTNAME;
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

function convertDomainToAscii($domain) {
    if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $domain)) {
        return idn_to_ascii($domain);
    } else {
        return $domain;
    }
}
function convertDomainToUtf8($domain) {
    if (preg_match('/^xn--/', $domain)) {
        return idn_to_utf8($domain);
    } else {
        return $domain;
    }
}

function getDomainDate($domain)
{
    try {
        $whois = \Iodev\Whois\Factory::get()->createWhois();
        $info = $whois->loadDomainInfo($domain);
        if ($info) {
            if ($info->expirationDate > 0) {
                return [$info->creationDate > 0 ? date('Y-m-d H:i:s', $info->creationDate) : null, date('Y-m-d H:i:s', $info->expirationDate)];
            } else {
                throw new Exception('域名到期时间未知');
            }
        } else {
            throw new Exception('域名信息未找到');
        }
    } catch (Exception $e) {
        throw new Exception('查询域名whois失败: ' . $e->getMessage());
    }
}
