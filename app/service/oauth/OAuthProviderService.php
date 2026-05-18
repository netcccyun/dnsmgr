<?php

namespace app\service\oauth;

use app\lib\oauth\OAuthProviderConfigValidator;
use Exception;
use think\facade\Db;

class OAuthProviderService
{
    private array $sortFields = ['id', 'name', 'type', 'enabled', 'sort', 'addtime'];

    public function list(int $offset, int $limit, string $sort, string $order): array
    {
        $sort = in_array($sort, $this->sortFields, true) ? $sort : 'sort';
        $order = strtolower($order) === 'desc' ? 'desc' : 'asc';
        $query = Db::name('oauth_provider');
        $total = $query->count();
        $rows = Db::name('oauth_provider')->order($sort, $order)->limit($offset, $limit)->select()->toArray();
        foreach ($rows as &$row) {
            $row = $this->redactSecret($row);
        }
        unset($row);
        return ['total' => $total, 'rows' => $rows];
    }

    public function get(int $id): array
    {
        $row = Db::name('oauth_provider')->where('id', $id)->find();
        if (!$row) {
            throw new Exception('提供商不存在');
        }
        return $this->redactSecret($row);
    }

    public function save(array $data, bool $isEdit, int $id = 0): void
    {
        if ($isEdit && $id <= 0) {
            throw new Exception('参数错误');
        }
        $provider = null;
        if ($isEdit) {
            $provider = $this->getRaw($id);
        }
        $data = (new OAuthProviderConfigValidator())->validate($data, $isEdit);
        if (in_array($data['type'], ['qq', 'cccyun'], true)) {
            $data['scopes'] = '';
        }
        $data['logo'] = $this->validateLogo((string)($data['logo'] ?? ''));
        if ($isEdit && empty($data['client_secret'])) {
            unset($data['client_secret']);
        }

        if ($isEdit) {
            Db::transaction(function () use ($id, $data, $provider) {
                if (config_get('oauth_disable_password', '0') == '1' && $provider && $provider['enabled'] == 1) {
                    $lockedProvider = Db::name('oauth_provider')->where('id', $id)->lock(true)->find();
                    if (!$lockedProvider) {
                        throw new Exception('提供商不存在');
                    }
                    $excludeId = (isset($data['enabled']) && (int)$data['enabled'] === 0) ? $id : 0;
                    if (!$this->hasAdminOauthBinding($excludeId, true)) {
                        throw new Exception('密码登录已禁用，至少需要保留一个超级管理员可用的OAuth绑定');
                    }
                    if ($this->isCriticalChange($lockedProvider, $data) && !$this->hasAdminOauthBinding($id, true)) {
                        throw new Exception('密码登录已禁用，不能修改最后一个超级管理员可用OAuth提供商的关键配置');
                    }
                }
                Db::name('oauth_provider')->where('id', $id)->update($data);
            });
            return;
        }
        $data['addtime'] = date('Y-m-d H:i:s');
        Db::name('oauth_provider')->insert($data);
    }

    public function delete(int $id): void
    {
        Db::transaction(function () use ($id) {
            $provider = Db::name('oauth_provider')->where('id', $id)->lock(true)->find();
            if (!$provider) {
                throw new Exception('提供商不存在');
            }
            if (config_get('oauth_disable_password', '0') == '1') {
                if ($provider['enabled'] == 1 && !$this->hasAdminOauthBinding($id, true)) {
                    throw new Exception('密码登录已禁用，至少需要保留一个超级管理员可用的OAuth绑定');
                }
            }
            Db::name('oauth_provider')->where('id', $id)->delete();
            Db::name('user_oauth')->where('provider_id', $id)->delete();
        });
    }

    public function hasAdminOauthBinding(int $excludeProviderId = 0, bool $lock = false, int $excludeUserId = 0): bool
    {
        $query = Db::name('user_oauth')->alias('uo')
            ->join('user u', 'u.id = uo.user_id')
            ->join('oauth_provider op', 'op.id = uo.provider_id')
            ->where('u.status', 1)
            ->where('u.level', 2)
            ->where('op.enabled', 1);
        if ($excludeProviderId > 0) {
            $query->where('op.id', '<>', $excludeProviderId);
        }
        if ($excludeUserId > 0) {
            $query->where('u.id', '<>', $excludeUserId);
        }
        if ($lock) {
            $query->lock(true);
        }
        return $query->count() > 0;
    }

    public function getEnabledProviders(): array
    {
        $providers = Db::name('oauth_provider')
            ->where('enabled', 1)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        foreach ($providers as &$provider) {
            $provider = $this->redactSecret($provider);
        }
        unset($provider);
        return $providers;
    }

    public function getEnabledProvidersWithUserBindings(int $userId): array
    {
        $providers = $this->getEnabledProviders();
        $bindings = Db::name('user_oauth')
            ->where('user_id', $userId)
            ->select();
        $boundMap = [];
        foreach ($bindings as $binding) {
            $displayName = $binding['nickname'] ?: ($binding['email'] ?: $binding['openid']);
            $boundMap[$binding['provider_id']] = [
                'display_name' => $displayName,
                'avatar' => $binding['avatar'] ?: '',
            ];
        }
        foreach ($providers as &$provider) {
            if (isset($boundMap[$provider['id']])) {
                $provider['bind_display_name'] = $boundMap[$provider['id']]['display_name'];
                $provider['bind_avatar'] = $boundMap[$provider['id']]['avatar'];
            }
        }
        unset($provider);
        return $providers;
    }

    private function getRaw(int $id): array
    {
        $row = Db::name('oauth_provider')->where('id', $id)->find();
        if (!$row) {
            throw new Exception('提供商不存在');
        }
        return $row;
    }

    private function redactSecret(array $row): array
    {
        $row['client_secret'] = '';
        return $row;
    }

    private function validateLogo(string $logo): string
    {
        if ($logo === '') {
            return '';
        }
        if (str_starts_with($logo, 'fa-')) {
            if (!preg_match('/^fa-[a-z0-9-]{1,64}$/i', $logo)) {
                throw new Exception('Logo 图标类名格式不正确');
            }
            return $logo;
        }
        $parts = parse_url($logo);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || strlen($logo) > 255) {
            throw new Exception('Logo URL 必须是长度不超过 255 的 HTTPS 地址');
        }
        return $logo;
    }

    private function isCriticalChange(array $provider, array $data): bool
    {
        foreach (['type', 'client_id', 'oauth_authorize_url', 'oauth_token_url', 'oauth_userinfo_url', 'oidc_issuer', 'scopes', 'userinfo_fields', 'ext_config'] as $field) {
            if ((string)($provider[$field] ?? '') !== (string)($data[$field] ?? '')) {
                return true;
            }
        }
        return !empty($data['client_secret']);
    }
}
