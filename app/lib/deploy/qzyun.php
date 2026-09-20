<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class qzyun implements DeployInterface
{
    private const BASE_URL = 'https://panel.qzyun.cn';
    private const ROUTER_STATE_TREE = '%5B%22%22%2C%7B%22children%22%3A%5B%22(app)%22%2C%7B%22children%22%3A%5B%22(product)%22%2C%7B%22children%22%3A%5B%22certificate%22%2C%7B%22children%22%3A%5B%22__PAGE__%22%2C%7B%7D%2Cnull%2Cnull%5D%7D%2Cnull%2Cnull%5D%7D%2Cnull%2Cnull%5D%7D%2Cnull%2Cnull%5D%7D%2Cnull%2Cnull%2Ctrue%5D';

    private $logger;
    private string $email;
    private string $password;
    private bool $proxy;
    private string $cookie = '';
    private array $actions = [];
    private bool $certificateWasAdded = false;

    public function __construct($config)
    {
        $this->email = trim($config['email'] ?? '');
        $this->password = $config['password'] ?? '';
        $this->proxy = ($config['proxy'] ?? 0) == 1;
    }

    public function check()
    {
        $this->validateAccount();
        $this->login();
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $this->validateAccount();

        $certificate = trim($config['certificate'] ?? '');
        $domain = trim($config['domain'] ?? '');
        if ($certificate === '') {
            throw new Exception('证书名称或文档ID不能为空');
        }
        if (trim($fullchain) === '' || trim($privatekey) === '') {
            throw new Exception('证书或私钥内容为空');
        }

        $this->login();
        $page = $this->request('/certificate');
        if ($page['code'] !== 200) {
            throw new Exception('获取全栈云证书列表失败(httpCode=' . $page['code'] . ')');
        }

        if ($this->isDocumentId($certificate)) {
            $documentId = $certificate;
        } else {
            try {
                $documentId = $this->findCertificateDocumentId($page['body'], $certificate);
            } catch (Exception $e) {
                if (!str_contains($e->getMessage(), '未找到证书')) {
                    throw $e;
                }
                $documentId = $this->addCertificate($page['body'], $certificate, $fullchain, $privatekey);
            }
        }

        if (!$this->certificateWasAdded) {
            $actionId = $this->findAction($page['body'], 'updateCertificateAction');
            $response = $this->postAction('/certificate', $actionId, [
                '1_certDocumentId' => $documentId,
                '1_serverCertificate' => $fullchain,
                '1_privateKey' => $privatekey,
                '0' => '[{"success":false,"message":""},"$K1"]',
            ]);
            $result = $this->parseActionResult($response['body']);

            if ($response['code'] !== 200 || !$result || ($result['success'] ?? false) !== true) {
                $message = $result['message'] ?? '请求失败(httpCode=' . $response['code'] . ')';
                throw new Exception('全栈云证书更新失败：' . $message);
            }
        }

        if ($domain !== '') {
            $this->bindDomain($domain, $documentId, $certificate, $fullchain, $privatekey);
        }

        $info['config']['certificate'] = $documentId;
        $this->log('全栈云证书处理成功，文档ID：' . $documentId);
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function validateAccount(): void
    {
        if ($this->email === '' || $this->password === '') {
            throw new Exception('请填写全栈云登录邮箱和密码');
        }
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('全栈云登录邮箱格式不正确');
        }
    }

    private function login(): void
    {
        $page = $this->request('/login');
        if ($page['code'] !== 200) {
            throw new Exception('打开全栈云登录页失败(httpCode=' . $page['code'] . ')');
        }

        $actionId = $this->findAction($page['body'], 'login');
        $response = $this->postAction('/login', $actionId, [
            '1_email' => $this->email,
            '1_password' => $this->password,
            '0' => '[{"error":null,"success":false},"$K1"]',
        ]);
        $this->cookie = $this->extractCookies($response['headers']);

        // Next.js Server Actions redirect with 303 after a successful login.
        if ($response['code'] >= 300 && $response['code'] < 400) {
            if ($this->cookie === '') {
                throw new Exception('全栈云登录失败：重定向响应未返回登录会话');
            }
            return;
        }

        $result = $this->parseActionResult($response['body']);

        if ($response['code'] !== 200 || ($result && ($result['success'] ?? false) === false)) {
            $message = $result['error'] ?? $result['message'] ?? '请求失败(httpCode=' . $response['code'] . ')';
            throw new Exception('全栈云登录失败：' . $message);
        }

        if ($this->cookie === '') {
            throw new Exception('全栈云登录失败：未获取到登录会话');
        }
    }

    private function addCertificate(string $page, string $name, string $fullchain, string $privatekey): string
    {
        $actionId = $this->findAction($page, 'addCertificateAction');
        $response = $this->postAction('/certificate', $actionId, [
            '1_certificateName' => $name,
            '1_serverCertificate' => $fullchain,
            '1_privateKey' => $privatekey,
            '0' => '[{"success":false,"message":""},"$K1"]',
        ]);
        $result = $this->parseActionResult($response['body']);
        if ($response['code'] !== 200 || !$result || ($result['success'] ?? false) !== true) {
            $message = $result['message'] ?? '请求失败(httpCode=' . $response['code'] . ')';
            throw new Exception('全栈云证书添加失败：' . $message);
        }

        $page = $this->request('/certificate');
        $documentId = $this->findCertificateDocumentId($page['body'], $name);
        $this->certificateWasAdded = true;
        $this->log('全栈云证书添加成功，文档ID：' . $documentId);
        return $documentId;
    }

    private function bindDomain(string $domain, string $documentId, string $certificate, string $fullchain, string $privatekey): void
    {
        $domainPage = $this->request('/cdn/domain/' . rawurlencode($domain));
        if ($domainPage['code'] !== 200) {
            throw new Exception('获取全栈云域名信息失败(httpCode=' . $domainPage['code'] . ')');
        }
        $domainInfo = $this->findDomainInfo($domainPage['body'], $domain);
        if (!$domainInfo) {
            throw new Exception('全栈云中未找到域名“' . $domain . '”');
        }

        $certificatePage = $this->request('/certificate');
        if ($this->isCertificateBound($certificatePage['body'], $documentId, $domain)) {
            $this->log('全栈云证书已绑定域名，跳过：' . $domain);
            return;
        }
        $actionId = $this->findAction($certificatePage['body'], 'bindDomainCdnServer');
        $certificateName = $this->isDocumentId($certificate)
            ? $this->findCertificateNameByDocumentId($certificatePage['body'], $documentId)
            : $certificate;
        $response = $this->postAction('/certificate', $actionId, [
            '1_domainName' => $domain,
            '1_Id' => (string)$domainInfo['id'],
            '1_documentId' => $domainInfo['documentId'],
            '1_CertificateName' => $certificateName,
            '1_ServerCertificate' => $fullchain,
            '1_CertificateDocumentId' => $documentId,
            '1_PrivateKey' => $privatekey,
            '0' => '[{"success":false,"message":""},"$K1"]',
        ]);
        $result = $this->parseActionResult($response['body']);
        if ($response['code'] !== 200 || !$result || ($result['success'] ?? false) !== true) {
            $message = $result['message'] ?? '请求失败(httpCode=' . $response['code'] . ')';
            throw new Exception('全栈云证书绑定域名失败：' . $message);
        }
        $this->log('全栈云证书已绑定域名：' . $domain);
    }

    private function isCertificateBound(string $html, string $documentId, string $domain): bool
    {
        $rsc = $this->extractRsc($html);
        $needle = '"documentId":' . json_encode($documentId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $position = strpos($rsc, $needle);
        if ($position === false) {
            return false;
        }
        $start = strrpos(substr($rsc, 0, $position), '{"id":');
        if ($start === false) {
            return false;
        }
        $object = $this->extractJsonObject($rsc, $start);
        $certificate = $object ? json_decode($object, true) : null;
        foreach (($certificate['cdnDomains'] ?? []) as $cdnDomain) {
            if (($cdnDomain['domain'] ?? null) === $domain) {
                return true;
            }
        }
        return false;
    }

    private function findDomainInfo(string $html, string $domain): ?array
    {
        $rsc = $this->extractRsc($html);
        $needle = '"domain":' . json_encode($domain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $offset = 0;
        while (($position = strpos($rsc, $needle, $offset)) !== false) {
            $start = strrpos(substr($rsc, 0, $position), '{"id":');
            if ($start !== false) {
                $object = $this->extractJsonObject($rsc, $start);
                $domainData = $object ? json_decode($object, true) : null;
                if (is_array($domainData)
                    && ($domainData['domain'] ?? null) === $domain
                    && isset($domainData['id'], $domainData['documentId'])) {
                    return ['id' => $domainData['id'], 'documentId' => $domainData['documentId']];
                }
            }
            $offset = $position + strlen($needle);
        }
        return null;
    }

    private function findCertificateNameByDocumentId(string $html, string $documentId): string
    {
        $rsc = $this->extractRsc($html);
        $needle = '"documentId":' . json_encode($documentId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $offset = 0;
        while (($position = strpos($rsc, $needle, $offset)) !== false) {
            $start = strrpos(substr($rsc, 0, $position), '{"id":');
            if ($start !== false) {
                $object = $this->extractJsonObject($rsc, $start);
                $certificate = $object ? json_decode($object, true) : null;
                if (is_array($certificate) && isset($certificate['name'], $certificate['content'])) {
                    return $certificate['name'];
                }
            }
            $offset = $position + strlen($needle);
        }
        throw new Exception('全栈云中未找到证书名称');
    }

    private function findAction(string $html, string $actionName): string
    {
        if (isset($this->actions[$actionName])) {
            return $this->actions[$actionName];
        }

        preg_match_all('/<script[^>]+src=["\']([^"\']+\.js[^"\']*)["\']/i', $html, $matches);
        foreach (array_unique($matches[1] ?? []) as $src) {
            $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5);
            $url = $this->sameOriginUrl($src);
            if ($url === null) {
                continue;
            }
            $response = $this->request($url);
            if ($response['code'] !== 200) {
                continue;
            }
            $name = preg_quote($actionName, '/');
            if (preg_match('/createServerReference\)\("([a-f0-9]{32,64})".{0,300},"' . $name . '"\)/s', $response['body'], $match)) {
                return $this->actions[$actionName] = $match[1];
            }
        }

        throw new Exception('无法识别全栈云接口动作：' . $actionName . '，可能是全栈云前端已更新');
    }

    private function findCertificateDocumentId(string $html, string $certificateName): string
    {
        $rsc = $this->extractRsc($html);
        $needle = '"name":' . json_encode($certificateName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $offset = 0;
        $documentIds = [];

        while (($position = strpos($rsc, $needle, $offset)) !== false) {
            $start = strrpos(substr($rsc, 0, $position), '{"id":');
            if ($start !== false) {
                $object = $this->extractJsonObject($rsc, $start);
                $certificate = $object ? json_decode($object, true) : null;
                if (is_array($certificate)
                    && ($certificate['name'] ?? null) === $certificateName
                    && isset($certificate['startTime'], $certificate['privateKey'], $certificate['content'], $certificate['documentId'])) {
                    $documentIds[$certificate['documentId']] = true;
                }
            }
            $offset = $position + strlen($needle);
        }

        $documentIds = array_keys($documentIds);
        if (count($documentIds) === 1) {
            return $documentIds[0];
        }
        if (count($documentIds) > 1) {
            throw new Exception('全栈云中存在多张同名证书，请改为填写证书文档ID');
        }
        throw new Exception('全栈云中未找到证书“' . $certificateName . '”');
    }

    private function extractRsc(string $html): string
    {
        preg_match_all('/self\.__next_f\.push\((\[.*?\])\)<\/script>/s', $html, $matches);
        $rsc = '';
        foreach ($matches[1] ?? [] as $payload) {
            $data = json_decode($payload, true);
            if (is_array($data) && ($data[0] ?? null) === 1 && is_string($data[1] ?? null)) {
                $rsc .= $data[1];
            }
        }
        return $rsc;
    }

    private function extractJsonObject(string $text, int $start): ?string
    {
        $length = strlen($text);
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    private function postAction(string $path, string $actionId, array $fields): array
    {
        $multipart = [];
        foreach ($fields as $name => $contents) {
            $multipart[] = ['name' => $name, 'contents' => $contents];
        }
        return $this->request($path, $multipart, [
            'Accept' => 'text/x-component',
            'next-action' => $actionId,
            'next-router-state-tree' => self::ROUTER_STATE_TREE,
            'Content-Type' => 'multipart/form-data',
        ], 'POST', 30);
    }

    private function parseActionResult(string $body): ?array
    {
        preg_match_all('/(?:^|\n)[a-z0-9]+:(\{[^\r\n]*\})/i', $body, $matches);
        foreach (array_reverse($matches[1] ?? []) as $json) {
            $result = json_decode($json, true);
            if (is_array($result) && (array_key_exists('success', $result)
                || array_key_exists('error', $result)
                || array_key_exists('message', $result))) {
                return $result;
            }
        }
        return null;
    }

    private function extractCookies(array $headers): string
    {
        $cookies = [];
        foreach ($headers as $name => $values) {
            if (strcasecmp($name, 'Set-Cookie') !== 0) {
                continue;
            }
            foreach ((array)$values as $value) {
                $pair = trim(explode(';', $value, 2)[0]);
                if ($pair !== '' && !str_ends_with($pair, '=') && !str_ends_with($pair, '=deleted')) {
                    $cookies[] = $pair;
                }
            }
        }
        return implode('; ', $cookies);
    }

    private function sameOriginUrl(string $url): ?string
    {
        if (str_starts_with($url, '/')) {
            return self::BASE_URL . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return $host === parse_url(self::BASE_URL, PHP_URL_HOST) ? $url : null;
    }

    private function request(string $path, $data = null, array $headers = [], string $method = 'GET', int $timeout = 15): array
    {
        $absolute = str_starts_with($path, 'http');
        $url = $absolute ? $path : self::BASE_URL . $path;
        $referer = $absolute ? self::BASE_URL . '/' : self::BASE_URL . $path;
        try {
            return http_request(
                $url,
                $data,
                $referer,
                $this->cookie ?: null,
                $headers,
                $this->proxy,
                $method,
                $timeout
            );
        } catch (Exception $e) {
            if (!preg_match('/SSL|TLS|handshake|alert/i', $e->getMessage())) {
                throw $e;
            }
            return $this->requestWithTls12($url, $data, $referer, $headers, $method, $timeout);
        }
    }

    private function requestWithTls12(string $url, $data, string $referer, array $headers, string $method, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new Exception('全栈云 TLS 连接失败，当前 PHP 未安装 cURL 扩展');
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/137.0.0.0 Safari/537.36',
            CURLOPT_REFERER => $referer,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$responseHeaders) {
                $length = strlen($line);
                $position = strpos($line, ':');
                if ($position !== false) {
                    $name = trim(substr($line, 0, $position));
                    $value = trim(substr($line, $position + 1));
                    $responseHeaders[$name][] = $value;
                }
                return $length;
            },
        ];

        if ($this->cookie !== '') {
            $options[CURLOPT_COOKIE] = $this->cookie;
        }
        if ($this->proxy) {
            curl_set_proxy($ch);
        }

        $isMultipart = isset($headers['Content-Type']) && $headers['Content-Type'] === 'multipart/form-data';
        if ($data !== null && $method !== 'GET') {
            if ($isMultipart && is_array($data)) {
                $postFields = [];
                foreach ($data as $part) {
                    if (isset($part['name'])) {
                        $postFields[$part['name']] = $part['contents'] ?? '';
                    }
                }
                $options[CURLOPT_POSTFIELDS] = $postFields;
                unset($headers['Content-Type']);
            } else {
                $options[CURLOPT_POSTFIELDS] = is_string($data) ? $data : http_build_query($data);
            }
        }

        if ($headers) {
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            $options[CURLOPT_HTTPHEADER] = $headerLines;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('全栈云 TLS 1.2 重试失败：' . $error);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '';
        curl_close($ch);

        return [
            'code' => $code,
            'redirect_url' => $redirectUrl,
            'headers' => $responseHeaders,
            'body' => $body,
        ];
    }

    private function isDocumentId(string $value): bool
    {
        return preg_match('/^[a-z0-9]{24}$/i', $value) === 1;
    }

    private function log(string $text): void
    {
        if ($this->logger) {
            call_user_func($this->logger, $text);
        }
    }
}
