<?php

namespace app\lib\oauth;

use Exception;

class OAuthProviderConfigValidator
{
    private array $types = ['qq', 'github', 'oauth2', 'oidc', 'cccyun'];

    public function __construct(private ?OAuthUrlValidator $urlValidator = null)
    {
        $this->urlValidator = $urlValidator ?: new OAuthUrlValidator();
    }

    public function validate(array $data, bool $isEdit = false): array
    {
        if (empty($data['name']) || empty($data['type']) || empty($data['client_id']) || (!$isEdit && empty($data['client_secret']))) {
            throw new Exception('请填写显示名称、提供商类型、Client ID 和 Client Secret（编辑时可留空 Secret）');
        }
        if (!in_array($data['type'], $this->types, true)) {
            throw new Exception('不支持的提供商类型');
        }
        if ($data['type'] === 'oauth2') {
            foreach (['oauth_authorize_url', 'oauth_token_url', 'oauth_userinfo_url'] as $field) {
                if (empty($data[$field])) {
                    throw new Exception('自定义 OAuth2 配置不完整：请填写授权端点 URL、Token 端点 URL、用户信息端点 URL');
                }
                $this->validateLength($data[$field], 1024, '端点 URL');
                if (!$this->urlValidator->isSafeHttpsUrl($data[$field])) {
                    throw new Exception('端点 URL 不可用：请确认使用 https:// 开头、域名可公网解析，且不能指向内网或 localhost');
                }
            }
        }
        if ($data['type'] === 'oidc') {
            if (empty($data['oidc_issuer'])) {
                throw new Exception('请填写 OIDC Issuer URL，例如 https://accounts.example.com，不要填写 /.well-known 完整路径');
            }
            $this->validateLength($data['oidc_issuer'], 1024, 'OIDC Issuer URL');
            if (!$this->urlValidator->isSafeHttpsUrl($data['oidc_issuer'])) {
                throw new Exception('OIDC Issuer URL 不可用：请确认使用 https:// 开头、域名可公网解析，且不能指向内网或 localhost');
            }
        }
        $this->validateLength($data['scopes'] ?? '', 1024, 'Scope');
        $this->validateLength($data['userinfo_fields'] ?? '', 65535, '字段映射');
        $this->validateJson($data['userinfo_fields'] ?? '', '字段映射', true);
        $this->validateLength($data['ext_config'] ?? '', 65535, '扩展配置');
        $this->validateJson($data['ext_config'] ?? '', '扩展配置');
        if ($data['type'] === 'cccyun') {
            $this->validateCccyunConfig($data['ext_config'] ?? '');
        }
        return $data;
    }

    private function validateCccyunConfig(string $extConfig): void
    {
        $ext = json_decode($extConfig, true);
        if (!is_array($ext) || ($ext !== [] && array_is_list($ext))) {
            throw new Exception('彩虹聚合登录扩展配置必须是有效JSON对象');
        }
        $type = (string)($ext['cccyun_type'] ?? '');
        if (!in_array($type, Cccyun::SUPPORTED_TYPES, true)) {
            throw new Exception('请选择有效的彩虹聚合登录方式');
        }
        $baseUrl = trim((string)($ext['cccyun_url'] ?? ''));
        if ($baseUrl !== '') {
            $this->validateLength($baseUrl, 1024, '彩虹聚合登录接口域名');
            if (!preg_match('/^https:\/\/[^\s\/?#]+\/?$/i', $baseUrl)) {
                throw new Exception('彩虹聚合登录接口域名请填写 https:// 开头的基础域名，例如 https://u.cccyun.cc/');
            }
            if (!$this->urlValidator->isSafeHttpsUrl(rtrim($baseUrl, '/') . '/connect.php')) {
                throw new Exception('彩虹聚合登录接口域名不可用：请确认域名可公网解析，且不能指向内网或 localhost');
            }
        }
    }

    private function validateLength(string $value, int $maxLength, string $name): void
    {
        if (strlen($value) > $maxLength) {
            throw new Exception($name . '长度不能超过' . $maxLength . '字节');
        }
    }

    private function validateJson(string $value, string $name, bool $requireStringValues = false): void
    {
        if ($value === '') {
            return;
        }
        $json = json_decode($value, true);
        if (!is_array($json) || ($json !== [] && array_is_list($json))) {
            throw new Exception($name . '必须是有效JSON对象');
        }
        if ($requireStringValues) {
            foreach ($json as $path) {
                if (!is_string($path) || trim($path) === '') {
                    throw new Exception($name . '字段路径必须是非空字符串');
                }
                foreach (explode(',', $path) as $candidatePath) {
                    if (trim($candidatePath) === '') {
                        throw new Exception($name . '字段路径候选项不能为空');
                    }
                }
            }
        }
    }
}
