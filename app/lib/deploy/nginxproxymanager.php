<?php

namespace app\lib\deploy;

use app\lib\DeployInterface;
use Exception;

class nginxproxymanager implements DeployInterface
{
    private $logger;
    private $url;
    private $email;
    private $password;
    private $proxy;
    private $token;

    public function __construct($config)
    {
        $this->url = rtrim($config['url'] ?? '', '/');
        $this->email = trim($config['email'] ?? '');
        $this->password = $config['password'] ?? '';
        $this->proxy = isset($config['proxy']) && $config['proxy'] == 1;
    }

    public function check()
    {
        if (empty($this->url) || empty($this->email) || empty($this->password)) {
            throw new Exception('请填写面板地址、登录邮箱和登录密码');
        }

        $this->login();
        $this->request('GET', '/nginx/certificates');
    }

    public function deploy($fullchain, $privatekey, $config, &$info)
    {
        $domains = $config['domainList'] ?? [];
        $domains = array_values(array_filter(array_map('trim', $domains)));
        if (empty($domains)) {
            throw new Exception('没有设置要部署的域名');
        }

        $this->login();

        $certificateId = intval($config['id'] ?? 0);
        if ($certificateId > 0) {
            $this->log('使用配置中的证书ID:' . $certificateId . ' 直接更新 NPM 自定义证书');
            $certificate = $this->getCertificate($certificateId);
            $this->assertCustomCertificate($certificate, $certificateId);
            $this->uploadCertificate($certificateId, $fullchain, $privatekey);
            $this->log('证书ID:' . $certificateId . ' 更新成功！');
            return;
        }

        $hostId = intval($config['host_id'] ?? 0);
        $hosts = $this->resolveTargetHosts($domains, $hostId);
        if (empty($hosts)) {
            throw new Exception('未找到匹配的 Proxy Host，请填写证书ID或 Proxy Host ID');
        }

        $this->log('匹配到 Proxy Host ' . count($hosts) . ' 个');

        $resolvedCertificateId = 0;
        $conflictMessage = null;
        foreach ($hosts as $host) {
            $hostCertificateId = intval($host['certificate_id'] ?? 0);
            if ($hostCertificateId <= 0) {
                continue;
            }

            try {
                $certificate = $this->getCertificate($hostCertificateId);
                $this->assertCustomCertificate($certificate, $hostCertificateId);

                if ($resolvedCertificateId === 0) {
                    $resolvedCertificateId = $hostCertificateId;
                } elseif ($resolvedCertificateId !== $hostCertificateId) {
                    $conflictMessage = '匹配到多个 Proxy Host，但它们绑定了不同的自定义证书ID，无法自动决定更新哪个证书，请手动填写证书ID';
                }
            } catch (Exception $e) {
                $this->log('Proxy Host ID:' . $host['id'] . ' 当前证书不可直接更新：' . $e->getMessage());
            }
        }

        if ($conflictMessage !== null) {
            throw new Exception($conflictMessage);
        }

        if ($resolvedCertificateId === 0) {
            $resolvedCertificateId = $this->createCustomCertificate($domains);
            $this->log('创建自定义证书成功，证书ID:' . $resolvedCertificateId);
        }

        $this->uploadCertificate($resolvedCertificateId, $fullchain, $privatekey);
        $this->log('证书ID:' . $resolvedCertificateId . ' 更新成功！');

        foreach ($hosts as $host) {
            $currentCertificateId = intval($host['certificate_id'] ?? 0);
            if ($currentCertificateId !== $resolvedCertificateId) {
                $this->updateProxyHostCertificate($host, $resolvedCertificateId);
                $this->log('Proxy Host ID:' . $host['id'] . ' 已绑定到证书ID:' . $resolvedCertificateId);
            } else {
                $this->log('Proxy Host ID:' . $host['id'] . ' 已绑定目标证书，无需重复更新绑定');
            }
        }

        $info['config']['id'] = (string)$resolvedCertificateId;
    }

    public function setLogger($func)
    {
        $this->logger = $func;
    }

    private function log($txt)
    {
        if ($this->logger) {
            call_user_func($this->logger, $txt);
        }
    }

    private function login()
    {
        $data = $this->request('POST', '/tokens', [
            'identity' => $this->email,
            'secret' => $this->password,
        ], false, false);

        if (empty($data['token'])) {
            if (!empty($data['requires_2fa'])) {
                throw new Exception('当前 NPM 账户启用了双因素认证，暂不支持');
            }
            throw new Exception('登录 NPM 失败，未返回访问令牌');
        }

        $this->token = $data['token'];
    }

    private function resolveTargetHosts(array $domains, int $hostId): array
    {
        if ($hostId > 0) {
            return [$this->getProxyHost($hostId)];
        }

        $hosts = $this->request('GET', '/nginx/proxy-hosts');
        if (!is_array($hosts)) {
            throw new Exception('获取 Proxy Host 列表失败');
        }

        $matched = [];
        foreach ($hosts as $host) {
            $hostDomains = $host['domain_names'] ?? [];
            if ($this->hasIntersectDomain($domains, $hostDomains)) {
                $matched[] = $this->getProxyHost(intval($host['id']));
            }
        }

        return $matched;
    }

    private function hasIntersectDomain(array $domains, array $hostDomains): bool
    {
        foreach ($hostDomains as $hostDomain) {
            $hostDomain = trim((string)$hostDomain);
            if ($hostDomain === '') {
                continue;
            }
            foreach ($domains as $domain) {
                if ($this->domainMatches($domain, $hostDomain) || $this->domainMatches($hostDomain, $domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function domainMatches(string $pattern, string $domain): bool
    {
        $pattern = strtolower(trim($pattern));
        $domain = strtolower(trim($domain));
        if ($pattern === '' || $domain === '') {
            return false;
        }
        if ($pattern === $domain) {
            return true;
        }
        if (str_starts_with($pattern, '*.')) {
            $suffix = substr($pattern, 1);
            return str_ends_with($domain, $suffix);
        }
        return false;
    }

    private function createCustomCertificate(array $domains): int
    {
        $result = $this->request('POST', '/nginx/certificates', [
            'provider' => 'other',
            'nice_name' => $this->buildCertificateName($domains),
        ]);

        if (isset($result['owner_user_id'])) {
            $this->log('NPM 新建证书归属用户ID:' . intval($result['owner_user_id']) . '（由当前登录账号决定）');
        }

        $certificateId = intval($result['id'] ?? 0);
        if ($certificateId <= 0) {
            throw new Exception('创建 NPM 自定义证书失败');
        }
        return $certificateId;
    }

    private function buildCertificateName(array $domains): string
    {
        return trim($domains[0]);
    }

    private function uploadCertificate(int $certificateId, string $fullchain, string $privatekey): void
    {
        [$certificate, $intermediateCertificate] = $this->splitFullchain($fullchain);

        $multipart = [
            [
                'name' => 'certificate',
                'filename' => 'certificate.pem',
                'contents' => $certificate,
            ],
            [
                'name' => 'certificate_key',
                'filename' => 'certificate.key',
                'contents' => $privatekey,
            ],
        ];

        if ($intermediateCertificate !== '') {
            $multipart[] = [
                'name' => 'intermediate_certificate',
                'filename' => 'intermediate.pem',
                'contents' => $intermediateCertificate,
            ];
        }

        $this->request(
            'POST',
            '/nginx/certificates/' . $certificateId . '/upload',
            $multipart,
            true,
            true,
            ['Content-Type' => 'multipart/form-data']
        );
    }

    private function splitFullchain(string $fullchain): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $fullchain, $matches);
        $certificates = array_values(array_filter(array_map('trim', $matches[0] ?? [])));
        if (empty($certificates)) {
            throw new Exception('证书内容格式错误，未找到 PEM 证书块');
        }

        $certificate = $certificates[0] . "\n";
        $intermediateCertificate = '';
        if (count($certificates) > 1) {
            $intermediateCertificate = implode("\n", array_slice($certificates, 1)) . "\n";
        }

        return [$certificate, $intermediateCertificate];
    }

    private function updateProxyHostCertificate(array $host, int $certificateId): void
    {
        $payload = [
            'certificate_id' => $certificateId,
        ];

        $this->request('PUT', '/nginx/proxy-hosts/' . intval($host['id']), $payload);
    }

    private function assertCustomCertificate(array $certificate, int $certificateId): void
    {
        if (($certificate['provider'] ?? '') !== 'other') {
            throw new Exception('证书ID:' . $certificateId . ' 不是自定义证书(provider=other)，无法通过上传接口更新');
        }
    }

    private function getCertificate(int $certificateId): array
    {
        $certificate = $this->request('GET', '/nginx/certificates/' . $certificateId);
        if (!is_array($certificate) || empty($certificate['id'])) {
            throw new Exception('证书ID:' . $certificateId . ' 不存在');
        }
        return $certificate;
    }

    private function getProxyHost(int $hostId): array
    {
        $host = $this->request('GET', '/nginx/proxy-hosts/' . $hostId);
        if (!is_array($host) || empty($host['id'])) {
            throw new Exception('Proxy Host ID:' . $hostId . ' 不存在');
        }

        $this->log('读取 Proxy Host ID:' . intval($host['id']) . ' owner_user_id:' . intval($host['owner_user_id'] ?? 0) . ' certificate_id:' . intval($host['certificate_id'] ?? 0));

        return $host;
    }

    private function request(string $method, string $path, $params = null, bool $auth = true, bool $logBodyOnError = true, array $extraHeaders = [])
    {
        $headers = $extraHeaders;
        if (!isset($headers['Content-Type']) && $params !== null && strtoupper($method) !== 'GET') {
            $headers['Content-Type'] = 'application/json';
        }
        if ($auth) {
            if (empty($this->token)) {
                throw new Exception('NPM 访问令牌不存在，请先登录');
            }
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $requestData = $params;
        if ($params !== null && isset($headers['Content-Type']) && strtolower($headers['Content-Type']) !== 'multipart/form-data') {
            $requestData = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = http_request(
            $this->url . '/api' . $path,
            $requestData,
            null,
            null,
            $headers,
            $this->proxy,
            $method,
            30
        );

        $body = $response['body'] ?? '';
        $result = json_decode($body, true);
        if ($response['code'] >= 200 && $response['code'] < 300) {
            return $result;
        }

        if ($logBodyOnError && $body !== '') {
            $this->log('Response:' . $body);
        }

        if (isset($result['error']['message'])) {
            throw new Exception($result['error']['message']);
        }
        if (isset($result['message'])) {
            throw new Exception($result['message']);
        }
        if (isset($result['error']) && is_string($result['error']) && $result['error'] !== '') {
            throw new Exception($result['error']);
        }
        if ($body !== '') {
            throw new Exception('请求失败(httpCode=' . $response['code'] . '): ' . $this->truncateResponseBody($body));
        }

        throw new Exception('请求失败(httpCode=' . $response['code'] . ')');
    }

    private function truncateResponseBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if (mb_strlen($body) > 300) {
            return mb_substr($body, 0, 300) . '...';
        }

        return $body;
    }
}
