<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class cloudpanel implements DeployInterface
{
    private $logger;
    private $url;
    private $username;
    private $password;
    private $proxy;
    private $cookie = '';

    public function __construct($config)
    {
        $this->url = rtrim($config['url'], '/');
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->proxy = isset($config['proxy']) && $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->username) || empty($this->password)) throw new Exception('请填写面板地址、用户名和密码');
        $this->login();
        return true;
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $this->assertAccountConfig();
        if (trim((string)$fullchain) === '' || trim((string)$privatekey) === '') {
            throw new Exception('SSL 证书或私钥内容不能为空');
        }

        $targets = $this->parseSites($config['sites'] ?? '');
        if (empty($targets)) {
            throw new Exception('没有设置要部署的网站域名');
        }

        $this->login();

        $success = 0;
        $errmsg = null;
        foreach ($targets as $domain) {
            try {
                $this->deploySite($domain, $fullchain, $privatekey);
                $this->log("网站 {$domain} 证书部署成功");
                $success++;
            } catch (Exception $e) {
                $errmsg = $e->getMessage();
                $this->log("网站 {$domain} 证书部署失败：" . $errmsg);
            }
        }
        if ($success == 0) {
            throw new Exception($errmsg ?: '要部署的网站不存在');
        }
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function assertAccountConfig(): void
    {
        if ($this->url === '' || $this->username === '' || $this->password === '') {
            throw new Exception('请填写面板地址、用户名和密码');
        }
        $parts = parse_url($this->url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new Exception('CloudPanel 面板地址格式无效');
        }
    }

    private function login(): void
    {
        $page = $this->request('GET', '/login');
        if ($page['code'] !== 200) {
            throw new Exception('打开 CloudPanel 登录页失败(httpCode=' . $page['code'] . ')');
        }

        $csrf = $this->extractValue($page['body'], '_csrf_token');
        if ($csrf === '') {
            throw new Exception('获取登录 CSRF 令牌失败');
        }

        $response = $this->request('POST', '/login', [
            'userName' => $this->username,
            'password' => $this->password,
            '_csrf_token' => $csrf,
        ], 20, $this->url . '/login');

        $location = $this->absoluteUrl($response['redirect_url'] ?? '');
        if ($this->isTwoFactorLocation($location) || $this->containsTwoFactor($response['body'] ?? '')) {
            throw new Exception('当前 CloudPanel 账户启用了双因素认证，暂不支持');
        }

        if ($response['code'] >= 300 && $response['code'] < 400) {
            if ($this->cookie === '') {
                throw new Exception('CloudPanel 登录失败：未获取到登录会话');
            }
            $home = $this->request('GET', $this->pathFromUrl($location) ?: '/');
            if ($this->isLoginPage($home['body'] ?? '', $home['redirect_url'] ?? '')) {
                throw new Exception('CloudPanel 登录失败，请检查用户名和密码');
            }
            return;
        }

        $alerts = $this->parseAlerts($response['body'] ?? '');
        if (!empty($alerts)) {
            throw new Exception('CloudPanel 登录失败：' . $alerts[0]);
        }
        if ($this->isLoginPage($response['body'] ?? '', $response['redirect_url'] ?? '')) {
            throw new Exception('CloudPanel 登录失败，请检查用户名和密码');
        }
        throw new Exception('CloudPanel 登录失败(httpCode=' . $response['code'] . ')');
    }

    private function listSites(): array
    {
        $response = $this->request('GET', '/');
        if ($this->isLoginPage($response['body'] ?? '', $response['redirect_url'] ?? '')) {
            throw new Exception('登录状态无效');
        }

        preg_match_all('#href="/site/([^"/]+)"#i', $response['body'] ?? '', $matches);
        $sites = [];
        foreach ($matches[1] as $name) {
            $name = trim(rawurldecode($name));
            if ($name === '' || strcasecmp($name, 'new') === 0) {
                continue;
            }
            $sites[$name] = $name;
        }
        return array_values($sites);
    }

    private function deploySite(string $domain, string $certificate, string $privatekey): void
    {
        $path = '/site/' . rawurlencode($domain) . '/certificate/import';
        $page = $this->request('GET', $path);
        if ($this->isLoginPage($page['body'] ?? '', $page['redirect_url'] ?? '')) {
            throw new Exception('打开证书导入页失败，站点不存在或登录已失效');
        }
        if ($page['code'] !== 200) {
            throw new Exception('打开证书导入页失败(httpCode=' . $page['code'] . ')');
        }

        $token = $this->extractValue($page['body'], 'site_import_certificate[_token]');
        if ($token === '') {
            $token = $this->extractValue($page['body'], 'site_import_certificate__token', true);
        }
        if ($token === '') {
            throw new Exception('获取证书导入表单令牌失败');
        }

        $response = $this->request('POST', $path, [
            'site_import_certificate' => [
                'privateKey' => $privatekey,
                'certificate' => $certificate,
                'certificateChain' => '',
                'submit' => 'Import and Install',
                '_token' => $token,
            ],
        ], 15, $this->url . $path);

        $location = $this->absoluteUrl($response['redirect_url'] ?? '');
        if ($response['code'] >= 300 && $response['code'] < 400) {
            if ($this->isLoginPage('', $location)) {
                throw new Exception('登录已失效，证书导入未完成');
            }
            if (str_contains($location, '/certificates') || str_contains($location, '/site/' . $domain)) {
                return;
            }
        }

        $alerts = $this->parseAlerts($response['body'] ?? '');
        if (!empty($alerts)) {
            throw new Exception(implode('；', $alerts));
        }
        if ($response['code'] === 200 && str_contains($response['body'] ?? '', 'site_import_certificate')) {
            throw new Exception('证书导入失败，请检查证书与私钥是否匹配');
        }
        if ($response['code'] >= 500) {
            throw new Exception('证书导入失败，面板返回 ' . $response['code'] . '，请确认证书格式后在 CloudPanel 后台手动导入排查');
        }
        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new Exception('证书导入失败(httpCode=' . $response['code'] . ')');
        }
    }

    private function parseSites($sites): array
    {
        $list = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)$sites) as $site) {
            $site = trim($site);
            if ($site === '') {
                continue;
            }
            $list[$site] = $site;
        }
        return array_values($list);
    }

    private function request(string $method, string $path, $params = null, int $timeout = 15, ?string $referer = null): array
    {
        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://') ? $path : $this->url . $path;
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
        if (strtoupper($method) === 'POST') {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $headers['Origin'] = $this->url;
        }

        $body = null;
        if ($params !== null) {
            $body = is_array($params) ? http_build_query($params) : $params;
        }

        $response = http_request(
            $url,
            $body,
            $referer ?: $this->url . '/',
            $this->cookie !== '' ? $this->cookie : null,
            $headers,
            $this->proxy,
            $method,
            $timeout
        );
        $this->mergeCookies($response['headers'] ?? []);
        return $response;
    }

    private function mergeCookies(array $headers): void
    {
        $map = [];
        if ($this->cookie !== '') {
            foreach (explode('; ', $this->cookie) as $pair) {
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                if ($name !== '') {
                    $map[$name] = $value;
                }
            }
        }
        foreach ($headers as $name => $values) {
            if (strcasecmp((string)$name, 'Set-Cookie') !== 0) {
                continue;
            }
            foreach ((array)$values as $value) {
                $pair = trim(explode(';', (string)$value, 2)[0]);
                if ($pair === '') {
                    continue;
                }
                [$cookieName, $cookieValue] = array_pad(explode('=', $pair, 2), 2, '');
                if ($cookieName === '') {
                    continue;
                }
                if ($cookieValue === '' || str_ends_with($pair, '=deleted')) {
                    unset($map[$cookieName]);
                    continue;
                }
                $map[$cookieName] = $cookieValue;
            }
        }
        $parts = [];
        foreach ($map as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        $this->cookie = implode('; ', $parts);
    }

    private function extractValue(string $html, string $name, bool $byId = false): string
    {
        $quoted = preg_quote($name, '/');
        $attr = $byId ? 'id' : 'name';
        if (preg_match('/' . $attr . '="' . $quoted . '"[^>]*value="([^"]*)"/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('/value="([^"]*)"[^>]*' . $attr . '="' . $quoted . '"/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    private function parseAlerts(string $html): array
    {
        $messages = [];
        if (preg_match_all('/class="alert alert-(?:danger|warning|success)"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
            foreach ($matches[1] as $item) {
                $text = trim(html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = preg_replace('/\s+/', ' ', $text);
                if ($text !== '') {
                    $messages[] = $text;
                }
            }
        }
        return $messages;
    }

    private function isLoginPage(string $html, string $location = ''): bool
    {
        $path = $this->pathFromUrl($this->absoluteUrl($location));
        if ($path === '/login') {
            return true;
        }
        return str_contains($html, 'name="userName"') && str_contains($html, 'action="/login"');
    }

    private function isTwoFactorLocation(string $location): bool
    {
        $path = strtolower($this->pathFromUrl($location));
        return str_contains($path, '2fa') || str_contains($path, 'two-factor') || str_contains($path, 'totp');
    }

    private function containsTwoFactor(string $html): bool
    {
        $html = strtolower($html);
        return str_contains($html, 'two-factor') || str_contains($html, 'authenticator') || str_contains($html, 'one-time password');
    }

    private function absoluteUrl(string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return '';
        }
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }
        if (str_starts_with($location, '/')) {
            return $this->url . $location;
        }
        return $this->url . '/' . ltrim($location, '/');
    }

    private function pathFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }
}
