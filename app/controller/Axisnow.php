<?php

namespace app\controller;

use app\BaseController;
use app\service\AxisNowAutomationService;
use app\service\AxisNowService;
use Exception;
use think\facade\Db;
use think\facade\View;

class Axisnow extends BaseController
{
    public function domains()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        View::assign('accounts', $this->accountList());
        return view();
    }

    public function domain()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        try {
            $context = $this->accountContext(input('param.id/d'));
            $uuid = $this->uuid(input('param.uuid', '', 'trim'));
            $domain = $context['service']->getDomain($uuid);
            View::assign([
                'accountId' => $context['account']['id'],
                'accountName' => $this->accountDisplayName($context['account']),
                'domain' => $domain,
            ]);
            return view();
        } catch (Exception $e) {
            return $this->alert('error', $e->getMessage());
        }
    }

    public function eips()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        View::assign('accounts', $this->accountList());
        return view();
    }

    public function tags()
    {
        if (!checkPermission(2)) return $this->alert('error', '无权限');
        View::assign('accounts', $this->accountList());
        return view();
    }

    public function domains_data()
    {
        return $this->listResponse(function (array $context) {
            return $context['service']->listDomains();
        }, ['domain', 'name', 'description', 'provider_type']);
    }

    public function eips_data()
    {
        return $this->listResponse(function (array $context) {
            $service = $context['service'];
            $tagNames = $this->indexNames($service->listTags());
            $rows = $this->availableEips($context);
            $referenceCounts = $this->routingReferenceCounts($rows, $service->listRules());
            foreach ($rows as &$row) {
                $row['owner_type'] = !empty($row['edge_uuid']) ? 'edge' : (!empty($row['cluster_uuid']) ? 'cluster' : '');
                $row['tag_names'] = [];
                foreach (($row['tag_uuids'] ?? []) as $tagUuid) {
                    $row['tag_names'][] = $tagNames[$tagUuid] ?? $tagUuid;
                }
                $row['routing_referenced_count'] = $referenceCounts[strtolower((string)($row['uuid'] ?? ''))] ?? 0;
            }
            unset($row);
            return $rows;
        }, ['address', 'tag_names', 'provider_name']);
    }

    public function tags_data()
    {
        return $this->listResponse(function (array $context) {
            return $context['service']->listTags();
        }, ['name', 'description']);
    }

    public function rules_data()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限', 'total' => 0, 'rows' => []]);
        try {
            $context = $this->accountContext(input('param.id/d'));
            $domainUuid = $this->uuid(input('param.uuid', '', 'trim'));
            $domain = $context['service']->getDomain($domainUuid);
            $lineNames = [];
            try {
                foreach ($this->geoIspOptions($context['service']->getGeoIspMetadata(), $domain) as $option) {
                    $lineNames[$option['value']] = $option['name'];
                }
            } catch (Exception $e) {
                // 路由规则列表仍可降级展示 AxisNow 原始线路值。
            }
            $tagNames = [];
            try {
                $tagNames = $this->indexNames($context['service']->listTags());
            } catch (Exception $e) {
                // 标签名称解析失败时回退展示标签 UUID，不影响规则列表加载。
            }
            $eipsByUuid = [];
            try {
                foreach ($this->availableEips($context) as $eip) {
                    $eip['tag_names'] = [];
                    foreach (($eip['tag_uuids'] ?? []) as $tagUuid) {
                        $eip['tag_names'][] = $tagNames[$tagUuid] ?? $tagUuid;
                    }
                    $eipUuid = strtolower(trim((string)($eip['uuid'] ?? '')));
                    if ($eipUuid !== '') $eipsByUuid[$eipUuid] = $eip;
                }
            } catch (Exception $e) {
                // EIP 元数据加载失败时仍展示地址和评分。
            }
            $rows = array_values(array_filter($context['service']->listRules(), static fn($row) => ($row['dns_domain_uuid'] ?? '') === $domainUuid));
            $automationByRule = [];
            foreach (Db::name('axisnow_rule_automation')
                ->where('account_id', $context['account']['id'])
                ->where('domain_uuid', $domainUuid)
                ->select() as $automation) {
                $automationByRule[strtolower((string)$automation['rule_uuid'])] = $automation;
            }
            $recordsByRule = [];
            $ruleUuids = array_values(array_filter(array_map(
                static fn($row) => trim((string)($row['uuid'] ?? '')),
                $rows
            )));
            try {
                foreach ($context['service']->listDnsRecordsByRuleUuids($ruleUuids) as $record) {
                    if (!is_array($record)) continue;
                    $ruleUuid = strtolower(trim((string)($record['dns_rule_uuid'] ?? $record['rule_uuid'] ?? '')));
                    if ($ruleUuid !== '') $recordsByRule[$ruleUuid][] = $record;
                }
            } catch (Exception $e) {
                // Keep the rule list available when the optional record index
                // is not supported by an older AxisNow tenant.
            }
            $latestEventsByRule = [];
            try {
                foreach ($context['service']->listLatestRuleEventsByRuleUuids($ruleUuids) as $event) {
                    if (!is_array($event)) continue;
                    $ruleInfo = is_array($event['dns_rule_info'] ?? null) ? $event['dns_rule_info'] : [];
                    $ruleUuid = strtolower(trim((string)($ruleInfo['dns_rule_uuid'] ?? $event['dns_rule_uuid'] ?? $event['rule_uuid'] ?? '')));
                    if ($ruleUuid !== '') $latestEventsByRule[$ruleUuid] = $event;
                }
            } catch (Exception $e) {
                // The timestamp is supplementary; it must not hide rules.
            }
            $probeStatusesByRule = [];
            try {
                foreach ($context['service']->listProbeTaskStatusesByRuleUuids($ruleUuids) as $probe) {
                    if (!is_array($probe)) continue;
                    $ruleUuid = strtolower(trim((string)($probe['uuid'] ?? $probe['dns_rule_uuid'] ?? '')));
                    if ($ruleUuid === '') continue;
                    $probeStatusesByRule[$ruleUuid] = is_array($probe['list'] ?? null) ? $probe['list'] : [];
                }
            } catch (Exception $e) {
                // Probe status is an optional enhancement for older tenants;
                // keep the route list available and let the UI show no data.
            }
            foreach ($rows as &$row) {
                $row['account_id'] = $context['account']['id'];
                $row['account_name'] = $this->accountDisplayName($context['account']);
                $row['strategy'] = $row['action']['conf']['response_strategy']['election_strategy'] ?? '-';
                $row['pool_summary'] = $this->poolSummary($row);
                $row['geo_isp_name'] = $lineNames[(string)($row['geo_isp'] ?? '')] ?? ($row['geo_isp'] ?? 'default');
                $ruleUuid = strtolower(trim((string)($row['uuid'] ?? '')));
                $automation = $automationByRule[$ruleUuid] ?? null;
                $row['automation'] = $automation ? [
                    'configured' => true,
                    'tide_enabled' => (bool)$automation['tide_enabled'],
                    'failover_enabled' => (bool)$automation['failover_enabled'],
                    'active_pool' => (string)$automation['active_pool'],
                    'failover_state' => (string)$automation['failover_state'],
                    'fail_count' => (int)$automation['fail_count'],
                    'failure_threshold' => (int)$automation['failure_threshold'],
                    'last_health_state' => (string)($automation['last_health_state'] ?? ''),
                    'last_switch_at' => (int)$automation['last_switch_at'],
                    'last_error' => (string)($automation['last_error'] ?? ''),
                ] : ['configured' => false];
                $row = array_merge($row, $this->rulePresentation(
                    $row,
                    $tagNames,
                    $eipsByUuid,
                    $recordsByRule[$ruleUuid] ?? [],
                    $latestEventsByRule[$ruleUuid] ?? [],
                    array_key_exists($ruleUuid, $probeStatusesByRule) ? $probeStatusesByRule[$ruleUuid] : null
                ));
            }
            unset($row);
            return json($this->paginateRows($rows, ['geo_isp', 'name', 'description', 'pool_summary', 'pool_search', 'resolved_search']));
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]);
        }
    }

    public function options()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        try {
            $context = $this->accountContext(input('param.id/d'));
            $scope = input('get.scope', 'domain', 'trim');
            $data = [];
            if ($scope === 'domain') {
                $data['providers'] = $this->providerOptions($context['service']->listDnsProviders(), 'self-hosted');
                $data['system_providers'] = $this->providerOptions($context['service']->listSystemDnsProviders(), 'platform');
            } elseif ($scope === 'eip') {
                $data['edges'] = $this->simpleOptions($context['service']->listEdges());
                $data['clusters'] = $this->simpleOptions($context['service']->listClusters());
                $data['tags'] = $this->simpleOptions($context['service']->listTags());
            } elseif ($scope === 'rule') {
                $domain = $context['service']->getDomain($this->uuid(input('get.domain_uuid', '', 'trim')));
                $data['eips'] = $this->eipOptions($this->availableEips($context));
                $data['tags'] = $this->simpleOptions($context['service']->listTags());
                $data['probe_templates'] = $this->simpleOptions($context['service']->listProbeTemplates());
                try {
                    $data['geo_isp_options'] = $this->geoIspOptions($context['service']->getGeoIspMetadata(), $domain);
                } catch (Exception $e) {
                    $data['geo_isp_options'] = [['value' => 'default', 'name' => '默认线路', 'depth' => 0, 'disabled' => false]];
                }
            } else {
                throw new Exception('选项范围无效');
            }
            return json(['code' => 0, 'data' => $data]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function domain_create()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $domain = $this->domainName(input('post.domain', '', 'trim'));
            $providerSource = input('post.provider_source', 'platform', 'trim');
            if (!in_array($providerSource, ['platform', 'self-hosted'], true)) {
                throw new Exception('托管类型无效');
            }
            $providerUuid = $this->uuid(input('post.dns_provider_uuid', '', 'trim'));
            $recordType = strtoupper(input('post.record_type', 'A', 'trim'));
            if (!in_array($recordType, ['A', 'CNAME'], true)) {
                throw new Exception('记录类型无效');
            }
            $data = [
                'domain' => $domain,
                'dns_provider_uuid' => $providerUuid,
                'record_type' => $recordType,
                'provider_source' => $providerSource,
            ];
            $zoneUuid = trim((string)input('post.dns_zone_uuid', '', 'trim'));
            if ($providerSource === 'platform') {
                if ($zoneUuid === '') throw new Exception('请选择 AxisNow 托管域名后缀');
                $zoneUuid = $this->uuid($zoneUuid);
                $this->assertManagedDomainZone($context['service'], $domain, $providerUuid, $zoneUuid);
                $data['dns_zone_uuid'] = $zoneUuid;
            } elseif ($zoneUuid !== '') {
                $data['dns_zone_uuid'] = $this->uuid($zoneUuid);
            }
            $name = trim((string)input('post.name', '', 'trim'));
            $description = trim((string)input('post.description', '', 'trim'));
            if ($name !== '') $data['name'] = mb_substr($name, 0, 50);
            if ($description !== '') $data['description'] = mb_substr($description, 0, 255);
            $result = $context['service']->createDomain($data);
            $this->addLog($context['account']['name'], '创建AxisNow调度域名', $domain . ' [' . $recordType . ', ' . $providerSource . ']');
            return ['msg' => '调度域名创建成功', 'data' => $result];
        });
    }

    public function domain_delete()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $uuid = $this->uuid(input('post.uuid', '', 'trim'));
            $domain = $context['service']->getDomain($uuid);
            $context['service']->deleteDomain($uuid);
            AxisNowAutomationService::removeDomain((int)$context['account']['id'], $uuid);
            $this->addLog($context['account']['name'], '删除AxisNow调度域名', ($domain['domain'] ?? $uuid));
            return ['msg' => '调度域名删除成功'];
        });
    }

    public function domain_update()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $uuid = $this->uuid(input('post.uuid', '', 'trim'));
            $current = $context['service']->getDomain($uuid);
            // AxisNow does not allow renaming an existing DNS routing domain.
            // Always send the provider's current value, even if a client tries
            // to supply a different domain in the update request.
            $domain = $this->domainName((string)($current['domain'] ?? ''));
            $providerSource = (string)($current['provider_source'] ?? '');
            if (!in_array($providerSource, ['platform', 'self-hosted'], true)) {
                $providerSource = !empty($current['dns_zone_uuid']) ? 'platform' : 'self-hosted';
            }
            // A platform-managed domain is permanently tied to the managed
            // zone and provider chosen when it was created. Do not let stale
            // option lists silently switch it to the first provider.
            $providerUuid = $this->uuid($providerSource === 'platform'
                ? (string)($current['dns_provider_uuid'] ?? '')
                : input('post.dns_provider_uuid', '', 'trim'));
            $data = [
                'domain' => $domain,
                'dns_provider_uuid' => $providerUuid,
                'name' => mb_substr(trim((string)input('post.name', '', 'trim')), 0, 50),
                'description' => mb_substr(trim((string)input('post.description', '', 'trim')), 0, 255),
                'share_default' => input('post.share_default/d', 0) === 1,
                'expose_eips' => input('post.expose_eips/d', 0) === 1,
            ];
            $zoneUuid = $providerSource === 'platform'
                ? trim((string)($current['dns_zone_uuid'] ?? ''))
                : trim((string)input('post.dns_zone_uuid', '', 'trim'));
            if ($providerSource === 'platform') {
                if ($zoneUuid === '') throw new Exception('请选择 AxisNow 托管域名后缀');
                $zoneUuid = $this->uuid($zoneUuid);
                $data['dns_zone_uuid'] = $zoneUuid;
            } elseif ($zoneUuid !== '') {
                $data['dns_zone_uuid'] = $this->uuid($zoneUuid);
            }
            if (!empty($current['status'])) $data['status'] = $current['status'];
            $result = $context['service']->updateDomain($uuid, $data);
            $this->addLog($context['account']['name'], '修改AxisNow调度域名', $data['domain']);
            return ['msg' => '调度域名修改成功', 'data' => $result];
        });
    }

    public function rule_get()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        try {
            $context = $this->accountContext(input('param.id/d'));
            $rule = $context['service']->getRule($this->uuid(input('param.uuid', '', 'trim')));
            return json(['code' => 0, 'data' => $rule]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function rule_save()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $domainUuid = $this->uuid(input('post.domain_uuid', '', 'trim'));
            $domain = $context['service']->getDomain($domainUuid);
            $payload = $this->buildRulePayload($domain);
            $uuid = trim((string)input('post.uuid', '', 'trim'));
            if ($uuid === '') {
                $result = $context['service']->createRule($payload);
                $verb = '创建';
            } else {
                $uuid = $this->uuid($uuid);
                $result = $context['service']->updateRule($uuid, $payload);
                AxisNowAutomationService::syncActivePool(
                    (int)$context['account']['id'],
                    $uuid,
                    $payload['action']['conf']['address_pool']
                );
                $verb = '修改';
            }
            $this->addLog($context['account']['name'], $verb . 'AxisNow路由规则', ($domain['domain'] ?? $domainUuid) . ' / ' . $payload['geo_isp']);
            return ['msg' => '路由规则' . $verb . '成功', 'data' => $result];
        });
    }

    public function rule_status()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $uuid = $this->uuid(input('post.uuid', '', 'trim'));
            $status = input('post.status', '', 'trim');
            if (!in_array($status, ['active', 'paused'], true)) throw new Exception('规则状态无效');
            $rule = $context['service']->getRule($uuid);
            $payload = $this->cleanRulePayload($rule);
            $payload['status'] = $status;
            $context['service']->updateRule($uuid, $payload);
            $this->addLog($context['account']['name'], ($status === 'active' ? '启用' : '暂停') . 'AxisNow路由规则', $rule['geo_isp'] ?? $uuid);
            return ['msg' => $status === 'active' ? '规则已启用' : '规则已暂停'];
        });
    }

    public function rule_delete()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $uuid = $this->uuid(input('post.uuid', '', 'trim'));
            $context['service']->deleteRule($uuid);
            AxisNowAutomationService::removeRule((int)$context['account']['id'], $uuid);
            $this->addLog($context['account']['name'], '删除AxisNow路由规则', $uuid);
            return ['msg' => '路由规则删除成功'];
        });
    }

    public function automation_get()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        try {
            $context = $this->accountContext(input('param.id/d'));
            $ruleUuid = $this->uuid(input('param.uuid', '', 'trim'));
            $rule = $context['service']->getRule($ruleUuid);
            $domainUuid = $this->uuid((string)($rule['dns_domain_uuid'] ?? ''));
            $row = Db::name('axisnow_rule_automation')
                ->where('account_id', $context['account']['id'])
                ->where('rule_uuid', $ruleUuid)
                ->find();
            $currentPool = $rule['action']['conf']['address_pool'] ?? [];
            if (!is_array($currentPool) || empty($currentPool['mode'])) throw new Exception('AxisNow 路由规则缺少地址池');

            $probeStatuses = null;
            try {
                $probeRows = $context['service']->listProbeTaskStatusesByRuleUuids([$ruleUuid]);
                $probeStatuses = AxisNowAutomationService::probeStatusesForRule($probeRows, $ruleUuid);
            } catch (Exception $e) {
                // Keep configuration readable if the optional probe endpoint
                // is temporarily unavailable.
            }
            $probeTemplateUuid = '';
            foreach ((array)($rule['action']['conf']['edge_probe_template_uuid'] ?? []) as $uuid) {
                $uuid = trim((string)$uuid);
                if ($uuid !== '') {
                    $probeTemplateUuid = $uuid;
                    break;
                }
            }
            $probeState = $probeTemplateUuid !== ''
                ? AxisNowAutomationService::healthState($rule, $probeStatuses)
                : 'not_configured';
            $probeStatusRows = array_values(array_filter(array_map(static function ($probe): array {
                if (!is_array($probe)) return [];
                $item = [
                    'address' => trim((string)($probe['target'] ?? $probe['address'] ?? '')),
                    'status' => strtolower(trim((string)($probe['status'] ?? ''))),
                ];
                return $item;
            }, $probeStatuses ?? []), static fn($probe) => ($probe['address'] ?? '') !== ''));

            $data = [
                'configured' => (bool)$row,
                'rule_uuid' => $ruleUuid,
                'domain_uuid' => $domainUuid,
                'rule_type' => strtoupper((string)($rule['type'] ?? 'A')),
                'geo_isp' => (string)($rule['geo_isp'] ?? 'default'),
                'primary_pool' => $this->decodeStoredPool($row['primary_pool'] ?? null, $currentPool),
                'tide_enabled' => (bool)($row['tide_enabled'] ?? false),
                'tide_start' => (string)($row['tide_start'] ?? '09:00'),
                'tide_end' => (string)($row['tide_end'] ?? '18:00'),
                'tide_pool' => $this->decodeStoredPool($row['tide_pool'] ?? null, null),
                'failover_enabled' => (bool)($row['failover_enabled'] ?? false),
                'failover_pool' => $this->decodeStoredPool($row['failover_pool'] ?? null, null),
                'failure_threshold' => (int)($row['failure_threshold'] ?? 3),
                'check_interval_minutes' => max(1, (int)ceil(((int)($row['check_interval'] ?? 300)) / 60)),
                'active_pool' => (string)($row['active_pool'] ?? 'primary'),
                'failover_state' => (string)($row['failover_state'] ?? 'armed'),
                'fail_count' => (int)($row['fail_count'] ?? 0),
                'last_check_at' => (int)($row['last_check_at'] ?? 0),
                'last_health_state' => (string)($row['last_health_state'] ?? ''),
                'last_switch_at' => (int)($row['last_switch_at'] ?? 0),
                'last_error' => (string)($row['last_error'] ?? ''),
                'has_probe_template' => $probeTemplateUuid !== '',
                'probe_template_uuid' => $probeTemplateUuid,
                'probe_state' => $probeState,
                'probe_statuses' => $probeStatusRows,
                'logs' => [],
            ];
            if ($row) {
                $data['logs'] = Db::name('axisnow_rule_automation_log')
                    ->where('automation_id', $row['id'])
                    ->order('id', 'desc')
                    ->limit(20)
                    ->select()
                    ->toArray();
            }
            return json(['code' => 0, 'data' => $data]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function automation_save()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $ruleUuid = $this->uuid(input('post.rule_uuid', '', 'trim'));
            $rule = $context['service']->getRule($ruleUuid);
            $domainUuid = $this->uuid((string)($rule['dns_domain_uuid'] ?? ''));
            $ruleType = strtoupper((string)($rule['type'] ?? 'A'));
            if (!in_array($ruleType, ['A', 'CNAME'], true)) throw new Exception('该路由规则类型不支持自动调度');
            $currentPool = $rule['action']['conf']['address_pool'] ?? null;
            if (!is_array($currentPool) || empty($currentPool['mode'])) throw new Exception('AxisNow 路由规则缺少地址池');

            $existing = Db::name('axisnow_rule_automation')
                ->where('account_id', $context['account']['id'])
                ->where('rule_uuid', $ruleUuid)
                ->find();
            if ($existing && (int)($existing['lock_until'] ?? 0) > time()) {
                throw new Exception('该规则正在执行自动调度，请稍后重试');
            }
            $primaryPool = $currentPool;
            if ($existing && (($existing['active_pool'] ?? 'primary') !== 'primary' || ($existing['failover_state'] ?? 'armed') === 'switched')) {
                $primaryPool = $this->decodeStoredPool($existing['primary_pool'] ?? null, $currentPool);
            }

            $tideEnabled = input('post.tide_enabled/d', 0) === 1;
            $failoverEnabled = input('post.failover_enabled/d', 0) === 1;
            if ($failoverEnabled && $ruleType !== 'A') throw new Exception('备份调度目前仅支持 A 记录路由规则');
            $tideStart = trim((string)input('post.tide_start', '09:00', 'trim'));
            $tideEnd = trim((string)input('post.tide_end', '18:00', 'trim'));
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $tideStart) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $tideEnd)) {
                throw new Exception('潮汐调度时间格式无效');
            }
            if ($tideEnabled && $tideStart === $tideEnd) throw new Exception('潮汐调度的开始和结束时间不能相同');

            $tidePool = $this->automationPoolInput('tide_pool', $ruleType, $existing['tide_pool'] ?? null);
            $failoverPool = $ruleType === 'A'
                ? $this->automationPoolInput('failover_pool', $ruleType, $existing['failover_pool'] ?? null)
                : null;
            if ($tideEnabled && !$tidePool) throw new Exception('请配置潮汐地址池');
            if ($failoverEnabled && !$failoverPool) throw new Exception('请配置故障备份地址池');
            if ($tideEnabled && $this->samePool($primaryPool, $tidePool)) throw new Exception('潮汐地址池不能与主地址池相同');
            if ($failoverEnabled && $this->samePool($primaryPool, $failoverPool)) throw new Exception('故障备份地址池不能与主地址池相同');
            if ($failoverEnabled) {
                if (empty(array_filter((array)($rule['action']['conf']['edge_probe_template_uuid'] ?? [])))) {
                    throw new Exception('开启备份调度前，请先为该路由规则选择地址监控模板');
                }
            }

            $threshold = max(1, min(10, input('post.failure_threshold/d', 3)));
            $intervalMinutes = max(1, min(60, input('post.check_interval_minutes/d', 5)));
            $now = time();
            $data = [
                'account_id' => (int)$context['account']['id'],
                'domain_uuid' => $domainUuid,
                'rule_uuid' => $ruleUuid,
                'rule_type' => $ruleType,
                'geo_isp' => mb_substr((string)($rule['geo_isp'] ?? 'default'), 0, 255),
                'primary_pool' => AxisNowAutomationService::encodePool($primaryPool),
                'tide_enabled' => $tideEnabled ? 1 : 0,
                'tide_start' => $tideStart,
                'tide_end' => $tideEnd,
                'tide_pool' => $tidePool ? AxisNowAutomationService::encodePool($tidePool) : null,
                'failover_enabled' => $failoverEnabled ? 1 : 0,
                'failover_pool' => $failoverPool ? AxisNowAutomationService::encodePool($failoverPool) : null,
                'failure_threshold' => $threshold,
                'check_interval' => $intervalMinutes * 60,
                'next_check_at' => $now,
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s', $now),
            ];
            if ($existing) {
                Db::name('axisnow_rule_automation')->where('id', $existing['id'])->update($data);
                $automationId = (int)$existing['id'];
            } else {
                $data += [
                    'fail_count' => 0,
                    'failover_state' => 'armed',
                    'active_pool' => 'primary',
                    'last_check_at' => 0,
                    'last_health_state' => '',
                    'last_switch_at' => 0,
                    'lock_until' => 0,
                    'created_at' => date('Y-m-d H:i:s', $now),
                ];
                $automationId = (int)Db::name('axisnow_rule_automation')->insertGetId($data);
            }
            Db::name('axisnow_rule_automation_log')->insert([
                'automation_id' => $automationId,
                'account_id' => (int)$context['account']['id'],
                'rule_uuid' => $ruleUuid,
                'action' => 'save',
                'status' => 'success',
                'message' => '自动调度配置已保存',
                'created_at' => date('Y-m-d H:i:s', $now),
            ]);
            $this->addLog($context['account']['name'], '保存AxisNow自动调度', (string)($rule['geo_isp'] ?? $ruleUuid));
            return ['msg' => '自动调度配置已保存'];
        });
    }

    public function automation_restore()
    {
        return $this->action(function () {
            $accountId = input('post.account_id/d');
            $context = $this->accountContext($accountId);
            $ruleUuid = $this->uuid(input('post.rule_uuid', '', 'trim'));
            (new AxisNowAutomationService())->restore($accountId, $ruleUuid);
            $this->addLog($context['account']['name'], '恢复AxisNow主地址池', $ruleUuid);
            return ['msg' => '已恢复主地址池并重新布防'];
        });
    }

    public function eip_create()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $targetType = input('post.target_type', 'edge', 'trim');
            if (!in_array($targetType, ['edge', 'cluster'], true)) throw new Exception('EIP 归属类型无效');
            $addresses = $this->expandEipAddresses($this->addressEntries(input('post.addresses', '', 'trim')));
            if (!$addresses) throw new Exception('至少填写一个 EIP 地址');
            $payload = [
                $targetType . '_uuid' => $this->uuid(input('post.target_uuid', '', 'trim')),
                'addresses' => $addresses,
                'tag_uuids' => $this->uuidList(input('post.tag_uuids/a', [])),
            ];
            $result = $context['service']->createEips($payload);
            $this->addLog($context['account']['name'], '创建AxisNow EIP', implode(', ', $addresses));
            return ['msg' => 'EIP 创建成功', 'data' => $result];
        });
    }

    public function eip_update()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $uuid = $this->uuid(input('post.uuid', '', 'trim'));
            $targetType = input('post.target_type', 'edge', 'trim');
            if (!in_array($targetType, ['edge', 'cluster'], true)) throw new Exception('EIP 归属类型无效');
            $address = trim((string)input('post.address', '', 'trim'));
            if ($address === '') throw new Exception('EIP 地址不能为空');
            $payload = [
                $targetType . '_uuid' => $this->uuid(input('post.target_uuid', '', 'trim')),
                'address' => $address,
                'tag_uuids' => $this->uuidList(input('post.tag_uuids/a', [])),
            ];
            $result = $context['service']->updateEip($uuid, $payload);
            $this->addLog($context['account']['name'], '修改AxisNow EIP', $address);
            return ['msg' => 'EIP 修改成功', 'data' => $result];
        });
    }

    public function eip_delete()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $uuids = $this->uuidList(input('post.uuids/a', []));
            if (!$uuids) throw new Exception('未选择 EIP');
            $context['service']->deleteEips($uuids);
            $this->addLog($context['account']['name'], '删除AxisNow EIP', implode(', ', $uuids));
            return ['msg' => 'EIP 删除成功'];
        });
    }

    public function tag_edit()
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        try {
            $context = $this->accountContext(input('get.account_id/d'));
            $tag = $context['service']->getTag($this->uuid(input('get.uuid', '', 'trim')));
            return json(['code' => 0, 'data' => $tag]);
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function tag_save()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $name = trim((string)input('post.name', '', 'trim'));
            if ($name === '') throw new Exception('标签名称不能为空');
            $description = trim((string)input('post.description', '', 'trim'));
            $payload = ['name' => mb_substr($name, 0, 50), 'description' => mb_substr($description, 0, 255)];
            $uuid = trim((string)input('post.uuid', '', 'trim'));
            if ($uuid === '') {
                $result = $context['service']->createTag($payload);
                $verb = '创建';
            } else {
                $result = $context['service']->updateTag($this->uuid($uuid), $payload);
                $verb = '修改';
            }
            $this->addLog($context['account']['name'], $verb . 'AxisNow标签', $name);
            return ['msg' => '标签' . $verb . '成功', 'data' => $result];
        });
    }

    public function tag_delete()
    {
        return $this->action(function () {
            $context = $this->accountContext(input('post.account_id/d'));
            $uuid = $this->uuid(input('post.uuid', '', 'trim'));
            $tag = $context['service']->getTag($uuid);
            $context['service']->deleteTag($uuid);
            $this->addLog($context['account']['name'], '删除AxisNow标签', $tag['name'] ?? $uuid);
            return ['msg' => '标签删除成功'];
        });
    }

    private function listResponse(callable $loader, array $searchFields)
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限', 'total' => 0, 'rows' => []]);
        try {
            $rows = [];
            foreach ($this->selectedAccounts() as $context) {
                foreach ($loader($context) as $row) {
                    $row['account_id'] = $context['account']['id'];
                    $row['account_name'] = $this->accountDisplayName($context['account']);
                    $rows[] = $row;
                }
            }
            return json($this->paginateRows($rows, array_merge($searchFields, ['account_name'])));
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage(), 'total' => 0, 'rows' => []]);
        }
    }

    private function paginateRows(array $rows, array $searchFields): array
    {
        $keyword = mb_strtolower(trim((string)input('post.kw', '', 'trim')));
        if ($keyword !== '') {
            $rows = array_values(array_filter($rows, static function ($row) use ($keyword, $searchFields) {
                foreach ($searchFields as $field) {
                    $value = $row[$field] ?? '';
                    if (is_array($value)) $value = implode(' ', $value);
                    if (str_contains(mb_strtolower((string)$value), $keyword)) return true;
                }
                return false;
            }));
        }
        $sort = input('post.sortName', '', 'trim');
        $order = strtolower(input('post.sortOrder', 'desc', 'trim')) === 'asc' ? 1 : -1;
        $allowedSort = ['domain', 'name', 'address', 'account_name', 'created_at', 'updated_at', 'referenced_count', 'routing_referenced_count', 'bound_count'];
        if (in_array($sort, $allowedSort, true)) {
            usort($rows, static fn($a, $b) => (($a[$sort] ?? '') <=> ($b[$sort] ?? '')) * $order);
        } else {
            usort($rows, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? $b['created_at'] ?? ''), (string)($a['updated_at'] ?? $a['created_at'] ?? '')));
        }
        $total = count($rows);
        $offset = max(0, input('post.offset/d', 0));
        $limit = max(1, min(200, input('post.limit/d', 20)));
        return ['code' => 0, 'total' => $total, 'rows' => array_slice($rows, $offset, $limit)];
    }

    private function selectedAccounts(): array
    {
        $accountId = input('post.account_id/d', 0);
        if ($accountId > 0) return [$this->accountContext($accountId)];
        $contexts = [];
        foreach (Db::name('account')->where('type', 'axisnow')->order('id', 'asc')->select()->toArray() as $account) {
            $contexts[] = $this->makeContext($account);
        }
        return $contexts;
    }

    private function accountList(): array
    {
        $accounts = Db::name('account')->where('type', 'axisnow')->order('id', 'asc')->field('id,name,remark')->select()->toArray();
        foreach ($accounts as &$account) {
            $account['name'] = $this->accountDisplayName($account);
        }
        unset($account);
        return $accounts;
    }

    private function accountDisplayName(array $account): string
    {
        $remark = trim((string)($account['remark'] ?? ''));
        if ($remark !== '') return $remark;
        $name = trim((string)($account['name'] ?? ''));
        return $name !== '' ? $name : ('AxisNow账户#' . ($account['id'] ?? ''));
    }

    private function accountContext(int $id): array
    {
        $account = Db::name('account')->where('id', $id)->where('type', 'axisnow')->find();
        if (!$account) throw new Exception('AxisNow 账号不存在');
        return $this->makeContext($account);
    }

    private function makeContext(array $account): array
    {
        $config = json_decode((string)($account['config'] ?? ''), true);
        if (!is_array($config)) throw new Exception('AxisNow 账号配置无效：' . $account['name']);
        $tenant = trim((string)($config['tenant'] ?? ''));
        if ($tenant === '') throw new Exception('AxisNow 账号缺少租户名，请先编辑账号配置：' . $account['name']);
        return ['account' => $account, 'tenant_name' => $tenant, 'service' => new AxisNowService($config)];
    }

    private function action(callable $callback)
    {
        if (!checkPermission(2)) return json(['code' => -1, 'msg' => '无权限']);
        try {
            $result = $callback();
            return json(array_merge(['code' => 0], $result));
        } catch (Exception $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function buildRulePayload(array $domain): array
    {
        $type = strtoupper((string)($domain['record_type'] ?? 'A'));
        if (!in_array($type, ['A', 'CNAME'], true)) throw new Exception('该域名的记录类型不支持路由规则');
        $providerType = (string)($domain['provider_type'] ?? '');
        $maxQuantity = $type === 'CNAME' && in_array($providerType, ['cloudflare', 'aws-route53', 'cloudns'], true) ? 1 : 10;
        $strategy = input('post.election_strategy', 'random', 'trim');
        if (!in_array($strategy, ['random', 'priority_order', 'quality_optimized'], true)) throw new Exception('选取策略无效');
        $poolType = input('post.pool_type', $type === 'CNAME' ? 'domain' : 'all_valid_eips', 'trim');
        $advancedPool = trim((string)input('post.advanced_pool', '', 'trim'));
        if ($advancedPool !== '') {
            $addressPool = json_decode($advancedPool, true);
            if (!is_array($addressPool) || empty($addressPool['mode'])) throw new Exception('高级地址池 JSON 格式无效');
            $quantity = max(1, min($maxQuantity, input('post.quantity/d', 1)));
        } elseif ($type === 'CNAME') {
            $domains = array_map([$this, 'hostName'], $this->stringList(input('post.pool_values', '', 'trim')));
            if (!$domains) throw new Exception('至少填写一个 CNAME 候选域名');
            $addressPool = ['mode' => 'customize', 'groups' => [['type' => 'domain', 'domains' => $domains]]];
            $quantity = max(1, min($maxQuantity, input('post.quantity/d', 1)));
        } elseif ($poolType === 'all_valid_eips') {
            if ($strategy === 'priority_order') throw new Exception('顺序策略需要选择指定 EIP、EIP 标签或自定义 IP');
            $addressPool = ['mode' => 'all_valid_eips'];
            $quantity = max(1, min(10, input('post.quantity/d', 1)));
        } else {
            $values = $poolType === 'ip' ? $this->stringList(input('post.pool_values', '', 'trim')) : $this->uuidList(input('post.pool_values/a', []));
            if (!$values) throw new Exception('地址池不能为空');
            $group = match ($poolType) {
                'ip' => ['type' => 'ip', 'ips' => $values],
                'eip' => ['type' => 'eip', 'eip_uuids' => $values],
                'eip_tag' => ['type' => 'eip_tag', 'tag_uuids' => $values],
                default => throw new Exception('地址池类型无效'),
            };
            $addressPool = ['mode' => 'customize', 'groups' => [$group]];
            $quantity = max(1, min(10, input('post.quantity/d', 1)));
        }
        $responseStrategy = [
            $type === 'CNAME' ? 'addr_quantity' : 'ip_quantity' => $quantity,
            'election_strategy' => $strategy,
        ];
        if ($strategy === 'quality_optimized') {
            $interval = input('post.trigger_interval/d', 5);
            $responseStrategy['trigger_interval'] = $interval === 10 ? 10 : 5;
        }
        $conf = ['address_pool' => $addressPool, 'response_strategy' => $responseStrategy];
        if ($type === 'CNAME') {
            if ($providerType === '') throw new Exception('AxisNow 未返回该域名的 DNS 提供商类型');
            $conf['provider_type'] = $providerType;
        }
        $probeUuid = trim((string)input('post.edge_probe_template_uuid', '', 'trim'));
        if ($probeUuid !== '') $conf['edge_probe_template_uuid'] = [$this->uuid($probeUuid)];
        $ttl = input('post.ttl/d', 0);
        if ($ttl > 0) {
            if ($ttl > 2592000) throw new Exception('TTL 超出 AxisNow 支持范围');
            $ttlConf = ['ttl' => $ttl];
            $plan = (string)($domain['zone_conf']['plan'] ?? '');
            if ($plan !== '') $ttlConf['plan'] = $plan;
            if ($type !== 'CNAME') {
                $ttlConf['provider_type'] = $providerType !== '' ? $providerType : 'self-hosted';
            }
            $conf['ttl_conf'] = $ttlConf;
        }
        $payload = [
            'type' => $type,
            'geo_isp' => trim((string)input('post.geo_isp', 'default', 'trim')) ?: 'default',
            'dns_domain_uuid' => $this->uuid((string)$domain['uuid']),
            'action' => ['method' => $type === 'CNAME' ? 'addr_election' : 'ip_election', 'conf' => $conf],
            'status' => input('post.status', 'active', 'trim') === 'paused' ? 'paused' : 'active',
        ];
        $name = $this->request->post('name', null, 'trim');
        $description = trim((string)input('post.description', '', 'trim'));
        if ($name !== null) $payload['name'] = mb_substr(trim((string)$name), 0, 100);
        $payload['description'] = mb_substr($description, 0, 255);
        return $payload;
    }

    private function cleanRulePayload(array $rule): array
    {
        $payload = [];
        foreach (['domain', 'type', 'geo_isp', 'name', 'description', 'dns_domain_uuid', 'action', 'status'] as $key) {
            if (array_key_exists($key, $rule)) $payload[$key] = $rule[$key];
        }
        return $payload;
    }

    private function poolSummary(array $row): string
    {
        $pool = $row['action']['conf']['address_pool'] ?? [];
        if (($pool['mode'] ?? '') === 'all_valid_eips') return '全部有效 EIP';
        $parts = [];
        foreach (($pool['groups'] ?? []) as $group) {
            $type = $group['type'] ?? '';
            $count = count($group['ips'] ?? $group['eip_uuids'] ?? $group['tag_uuids'] ?? $group['domains'] ?? []);
            $parts[] = match ($type) {
                'ip' => $count . ' 个自定义 IP',
                'eip' => $count . ' 个 EIP',
                'eip_tag' => $count . ' 个 EIP 标签',
                'domain' => $count . ' 个 CNAME',
                default => $count . ' 个地址',
            };
        }
        return $parts ? implode(' + ', $parts) : '-';
    }

    private function axisNowGeoValues($value): array
    {
        if (is_scalar($value)) {
            $value = trim((string)$value);
            return $value === '' ? [] : [$value];
        }
        if (!is_array($value)) return [];
        $values = [];
        foreach (['code', 'value', 'id', 'name', 'label'] as $key) {
            if (!array_key_exists($key, $value) || !is_scalar($value[$key])) continue;
            $item = trim((string)$value[$key]);
            if ($item !== '') $values[] = $item;
        }
        return $values;
    }

    private function axisNowIsSpecialRegion(string $value): bool
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[\s_.\/-]+/u', '', $normalized) ?: $normalized;
        return in_array($normalized, [
            'hk', 'hkg', '810', 'hongkong', '香港',
            'mo', 'mac', 'macao', 'macau', '446', '澳门',
            'tw', 'twn', 'taiwan', '158', '台湾',
        ], true) || str_ends_with($normalized, 'hk') || str_ends_with($normalized, 'mo') || str_ends_with($normalized, 'tw')
            || str_contains($normalized, 'hongkong') || str_contains($normalized, 'macao') || str_contains($normalized, 'macau') || str_contains($normalized, 'taiwan');
    }

    private function axisNowGeoField(array $geo, array $keys, bool $preferSpecial = false): string
    {
        $values = [];
        foreach ($keys as $key) {
            $values = array_merge($values, $this->axisNowGeoValues($geo[$key] ?? null));
        }
        if ($preferSpecial) {
            foreach ($values as $value) {
                if ($this->axisNowIsSpecialRegion((string)$value)) return $value;
            }
        }
        return $values[0] ?? '';
    }

    private function axisNowGeoMeta(array $eip): array
    {
        $geo = is_array($eip['geo'] ?? null) ? $eip['geo'] : $eip;
        $nested = [];
        foreach (['geo', 'location', 'address', 'region_info', 'subdivision_info'] as $key) {
            if (is_array($geo[$key] ?? null)) {
                $nested = $geo[$key];
                break;
            }
        }
        $countryCode = $this->axisNowGeoField($geo, ['country_code', 'countryCode', 'country']);
        $provinceKeys = [
            'province_code', 'provinceCode',
            'region_code', 'regionCode',
            'subdivision_code', 'subdivisionCode',
            'state_code', 'stateCode',
            'province', 'region', 'subdivision', 'state',
        ];
        $provinceCandidates = [$this->axisNowGeoField($geo, $provinceKeys, true)];
        $cityName = $this->axisNowGeoField($geo, ['city_name', 'cityName', 'city']);
        $ispName = $this->axisNowGeoField($geo, ['isp_name', 'ispName', 'isp']);
        if ($nested) {
            $countryCode = $countryCode !== '' ? $countryCode : $this->axisNowGeoField($nested, ['country_code', 'countryCode', 'country']);
            $provinceCandidates[] = $this->axisNowGeoField($nested, $provinceKeys, true);
            $cityName = $cityName !== '' ? $cityName : $this->axisNowGeoField($nested, ['city_name', 'cityName', 'city']);
            $ispName = $ispName !== '' ? $ispName : $this->axisNowGeoField($nested, ['isp_name', 'ispName', 'isp']);
        }
        $provinceCandidates = array_values(array_filter($provinceCandidates, static fn($value) => $value !== ''));
        $provinceCode = '';
        foreach ($provinceCandidates as $candidate) {
            if ($this->axisNowIsSpecialRegion((string)$candidate)) {
                $provinceCode = (string)$candidate;
                break;
            }
        }
        if ($provinceCode === '') $provinceCode = (string)($provinceCandidates[0] ?? '');
        return [
            'country_code' => $countryCode,
            'province_code' => $provinceCode,
            'isp_name' => $ispName,
            'provider_name' => trim((string)($eip['provider_name'] ?? '')),
            'tag_names' => is_array($eip['tag_names'] ?? null) ? array_values($eip['tag_names']) : [],
        ];
    }

    private function rulePresentation(array $row, array $tagNames, array $eipsByUuid, array $dnsRecords, array $latestEvent, ?array $probeStatuses = null): array
    {
        $conf = $row['action']['conf'] ?? [];
        $pool = $conf['address_pool'] ?? [];
        $response = $conf['response_strategy'] ?? [];
        $addressKey = static fn($address) => rtrim(strtolower(trim((string)$address)), '.');
        $expandedAddresses = [];
        $addressByUuid = [];
        $poolMetaByAddress = [];
        foreach (($row['address_pool_eips'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            $address = trim((string)($entry['address'] ?? ''));
            if ($address === '') continue;
            $expandedAddresses[] = $address;
            $uuid = strtolower(trim((string)($entry['uuid'] ?? '')));
            if ($uuid !== '') {
                $addressByUuid[$uuid] = $address;
                $eip = $eipsByUuid[$uuid] ?? [];
                $poolMetaByAddress[$addressKey($address)] = $this->axisNowGeoMeta($eip);
            }
        }

        $probeByAddress = [];
        foreach ($probeStatuses ?? [] as $probe) {
            if (!is_array($probe)) continue;
            $address = trim((string)($probe['target'] ?? $probe['address'] ?? ''));
            if ($address === '') continue;
            $status = strtolower(trim((string)($probe['status'] ?? '')));
            $item = [
                'address' => $address,
                'status' => $status,
            ];
            $probeByAddress[$addressKey($address)] = $item;
        }

        $groups = [];
        $literalAddresses = [];
        if (($pool['mode'] ?? '') === 'all_valid_eips') {
            $groups[] = [
                'type' => 'all_valid_eips',
                'type_name' => '全部有效 EIP',
                'count' => isset($row['eips_count']) ? (int)$row['eips_count'] : count($expandedAddresses),
                'items' => [],
            ];
        } else {
            foreach (($pool['groups'] ?? []) as $group) {
                if (!is_array($group)) continue;
                $type = (string)($group['type'] ?? '');
                $items = [];
                if ($type === 'eip') {
                    foreach (($group['eip_uuids'] ?? []) as $uuid) {
                        $key = strtolower((string)$uuid);
                        $items[] = $addressByUuid[$key] ?? (string)$uuid;
                    }
                } elseif ($type === 'eip_tag') {
                    foreach (($group['tag_uuids'] ?? []) as $uuid) {
                        $items[] = $tagNames[(string)$uuid] ?? (string)$uuid;
                    }
                } elseif ($type === 'ip') {
                    $items = $group['ips'] ?? [];
                    $literalAddresses = array_merge($literalAddresses, is_array($items) ? $items : []);
                } elseif ($type === 'domain') {
                    $items = $group['domains'] ?? [];
                    $literalAddresses = array_merge($literalAddresses, is_array($items) ? $items : []);
                }
                $items = array_values(array_unique(array_filter(array_map(
                    static fn($item) => trim((string)$item),
                    is_array($items) ? $items : []
                ), static fn($item) => $item !== '')));
                $groups[] = [
                    'type' => $type ?: 'unknown',
                    'type_name' => match ($type) {
                        'eip' => 'EIP',
                        'eip_tag' => 'EIP 标签',
                        'ip' => '自定义 IP',
                        'domain' => 'CNAME',
                        default => '地址',
                    },
                    'count' => count($items),
                    'items' => $items,
                ];
            }
        }

        $candidates = [];
        $candidateByAddress = [];
        foreach (($row['election_info']['list'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            $address = trim((string)($entry['address'] ?? ''));
            if ($address === '') continue;
            $stability = is_array($entry['stability_info'] ?? null) ? $entry['stability_info'] : [];
            $item = [
                'address' => $address,
                'status' => (string)($stability['status'] ?? ''),
                'quality_filtered' => filter_var($stability['quality_filtered'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
            if (isset($probeByAddress[$addressKey($address)])) {
                $item = array_merge($item, $probeByAddress[$addressKey($address)]);
            }
            if (isset($stability['score']) && is_numeric($stability['score'])) {
                $item['score'] = (float)$stability['score'];
            }
            $item = array_merge($item, $poolMetaByAddress[$addressKey($address)] ?? []);
            $candidates[] = $item;
            $candidateByAddress[$addressKey($address)] = $item;
        }
        foreach ($probeByAddress as $key => $probe) {
            if (isset($candidateByAddress[$key])) continue;
            $item = array_merge($probe, ['quality_filtered' => false], $poolMetaByAddress[$key] ?? []);
            $candidates[] = $item;
            $candidateByAddress[$key] = $item;
        }

        $quantity = $response['ip_quantity'] ?? $response['addr_quantity'] ?? null;
        $quantity = is_numeric($quantity) ? max(0, (int)$quantity) : null;
        $resolved = [];
        foreach ($dnsRecords as $record) {
            if (!is_array($record)) continue;
            $address = trim((string)($record['content'] ?? ''));
            if ($address === '') continue;
            $key = $addressKey($address);
            $item = $candidateByAddress[$key] ?? array_merge([
                'address' => $address,
                'status' => '',
                'quality_filtered' => false,
            ], $poolMetaByAddress[$key] ?? []);
            $item['address'] = $address;
            $item['sync_status'] = trim((string)($record['sync_status'] ?? ''));
            $item['fallback'] = filter_var($record['fallback'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $item['election_order'] = is_numeric($record['election_order'] ?? null) ? (int)$record['election_order'] : null;
            $item['updated_at'] = trim((string)($record['updated_at'] ?? ''));
            $resolved[] = $item;
        }
        usort($resolved, static function ($left, $right) {
            $leftScore = isset($left['score']) && is_numeric($left['score']) ? (float)$left['score'] : 0.0;
            $rightScore = isset($right['score']) && is_numeric($right['score']) ? (float)$right['score'] : 0.0;
            if ($leftScore !== $rightScore) return $rightScore <=> $leftScore;
            $leftOrder = $left['election_order'] ?? PHP_INT_MAX;
            $rightOrder = $right['election_order'] ?? PHP_INT_MAX;
            return $leftOrder <=> $rightOrder ?: strcmp((string)$left['address'], (string)$right['address']);
        });
        $resolvedUpdatedAt = trim((string)($latestEvent['time_iso8601'] ?? ''));

        $poolAddressValues = array_values(array_unique(array_filter(array_map(
            static fn($item) => trim((string)$item),
            array_merge($expandedAddresses, $literalAddresses)
        ), static fn($item) => $item !== '')));
        $poolAddresses = [];
        $poolAddressKeys = [];
        foreach ($poolAddressValues as $address) $poolAddressKeys[$addressKey($address)] = true;
        $addedPoolAddresses = [];
        foreach ($candidates as $candidate) {
            $key = $addressKey($candidate['address']);
            if (!isset($poolAddressKeys[$key]) || isset($addedPoolAddresses[$key])) continue;
            $poolAddresses[] = $candidate;
            $addedPoolAddresses[$key] = true;
        }
        foreach ($poolAddressValues as $address) {
            $key = $addressKey($address);
            if (isset($addedPoolAddresses[$key])) continue;
            $poolAddresses[] = array_merge([
                'address' => $address,
                'status' => '',
                'quality_filtered' => false,
            ], $poolMetaByAddress[$key] ?? []);
            $addedPoolAddresses[$key] = true;
        }
        $poolAddressCount = count($poolAddresses);
        if ((bool)($row['address_pool_eips_truncated'] ?? false)) {
            $poolAddressCount += max(0, (int)($row['eips_count'] ?? 0) - count($expandedAddresses));
        } elseif ($poolAddressCount === 0) {
            $poolAddressCount = max((int)($row['eips_count'] ?? 0), (int)($row['cnames_count'] ?? 0));
        }

        $probeTemplateUuid = '';
        foreach ((array)($conf['edge_probe_template_uuid'] ?? []) as $uuid) {
            $uuid = trim((string)$uuid);
            if ($uuid !== '') {
                $probeTemplateUuid = $uuid;
                break;
            }
        }
        $probeState = $probeTemplateUuid !== ''
            ? AxisNowAutomationService::healthState($row, $probeStatuses)
            : 'not_configured';
        $probeStatusRows = array_values(array_map(static function (array $probe): array {
            $item = [
                'address' => trim((string)($probe['target'] ?? $probe['address'] ?? '')),
                'status' => strtolower(trim((string)($probe['status'] ?? ''))),
            ];
            return $item;
        }, array_values(array_filter($probeStatuses ?? [], 'is_array'))));

        return [
            'pool_groups' => $groups,
            'pool_addresses' => $poolAddresses,
            'pool_address_count' => $poolAddressCount,
            'pool_truncated' => (bool)($row['address_pool_eips_truncated'] ?? false),
            'strategy_quantity' => $quantity,
            'strategy_interval' => isset($response['trigger_interval']) && is_numeric($response['trigger_interval']) ? (int)$response['trigger_interval'] : null,
            'resolved_addresses' => $resolved,
            'resolved_updated_at' => $resolvedUpdatedAt,
            'probe_template_uuid' => $probeTemplateUuid,
            'probe_state' => $probeState,
            'probe_statuses' => $probeStatusRows,
            'pool_search' => trim(implode(' ', array_merge(
                array_column($groups, 'type_name'),
                $poolAddressValues,
                ...array_map(static fn($group) => $group['items'], $groups)
            ))),
            'resolved_search' => implode(' ', array_column($resolved, 'address')),
        ];
    }

    private function availableEips(array $context): array
    {
        /** @var AxisNowService $service */
        $service = $context['service'];
        $owned = $service->listEips();
        foreach ($owned as &$row) {
            $row['data_origin'] = 'own';
        }
        unset($row);

        $subscribed = $service->listSubscribedEips();
        $sharerUuids = array_values(array_filter(array_map(
            static fn($row) => $row['sharer_tenant_uuid'] ?? '',
            $subscribed
        )));
        $providers = [];
        if ($sharerUuids) {
            try {
                $providers = $service->resolveSubscriptionProviders($sharerUuids);
            } catch (Exception $e) {
                // 提供商名称解析失败时仍展示已订阅 EIP，并回退到租户 UUID。
            }
        }

        $rows = array_merge($owned, $subscribed);
        foreach ($rows as &$row) {
            $origin = ($row['data_origin'] ?? '') === 'subscribed' ? 'subscribed' : 'own';
            $row['data_origin'] = $origin;
            $row['can_manage'] = $origin === 'own';
            if ($origin === 'own') {
                $row['provider_name'] = $context['tenant_name'];
                continue;
            }

            $sharerUuid = strtolower((string)($row['sharer_tenant_uuid'] ?? ''));
            $provider = $providers[$sharerUuid] ?? [];
            $row['provider_name'] = trim((string)($provider['name'] ?? ''))
                ?: (trim((string)($provider['host'] ?? '')) ?: ($sharerUuid ?: '其他租户'));
        }
        unset($row);
        return $rows;
    }

    private function routingReferenceCounts(array $eips, array $rules): array
    {
        $counts = [];
        $tagEips = [];
        foreach ($eips as $eip) {
            $uuid = strtolower((string)($eip['uuid'] ?? ''));
            if ($uuid === '') continue;
            $counts[$uuid] = 0;
            foreach (($eip['tag_uuids'] ?? []) as $tagUuid) {
                $tagEips[strtolower((string)$tagUuid)][$uuid] = true;
            }
        }

        foreach ($rules as $rule) {
            $pool = $rule['action']['conf']['address_pool'] ?? [];
            $referenced = [];
            if (($pool['mode'] ?? '') === 'all_valid_eips') {
                $referenced = array_fill_keys(array_keys($counts), true);
            } else {
                foreach (($pool['groups'] ?? []) as $group) {
                    if (($group['type'] ?? '') === 'eip') {
                        foreach (($group['eip_uuids'] ?? []) as $eipUuid) {
                            $referenced[strtolower((string)$eipUuid)] = true;
                        }
                    } elseif (($group['type'] ?? '') === 'eip_tag') {
                        foreach (($group['tag_uuids'] ?? []) as $tagUuid) {
                            foreach (($tagEips[strtolower((string)$tagUuid)] ?? []) as $eipUuid => $_) {
                                $referenced[$eipUuid] = true;
                            }
                        }
                    }
                }
            }
            foreach ($referenced as $eipUuid => $_) {
                if (array_key_exists($eipUuid, $counts)) $counts[$eipUuid]++;
            }
        }
        return $counts;
    }

    private function geoIspOptions(array $metadata, array $domain): array
    {
        $providerType = strtolower(trim((string)($domain['provider_type'] ?? '')));
        if ($providerType === '') $providerType = 'self-hosted';
        if ($providerType === 'dnspod' && !empty($domain['provider_is_international'])) {
            $providerType = 'dnspod-intl';
        }

        $provider = null;
        foreach ($metadata as $item) {
            if (($item['provider_type'] ?? '') === $providerType) {
                $provider = $item;
                break;
            }
        }
        if (!$provider) {
            foreach ($metadata as $item) {
                if (($item['provider_type'] ?? '') === 'self-hosted') {
                    $provider = $item;
                    break;
                }
            }
        }
        if (!$provider) return [['value' => 'default', 'name' => '默认线路', 'depth' => 0, 'disabled' => false]];

        $planName = strtolower(trim((string)($domain['zone_conf']['plan'] ?? '')));
        $plan = null;
        foreach (($provider['list'] ?? []) as $item) {
            if ($planName !== '' && strtolower((string)($item['provider_plan'] ?? '')) === $planName) {
                $plan = $item;
                break;
            }
        }
        if (!$plan) {
            foreach (($provider['list'] ?? []) as $item) {
                if (($item['provider_plan'] ?? '') === 'default') {
                    $plan = $item;
                    break;
                }
            }
        }
        if (!$plan) $plan = ($provider['list'] ?? [])[0] ?? [];

        $translations = $this->axisNowGeoIspTranslations();

        $options = [];
        $seen = [];
        $this->flattenGeoIspOptions($plan['options'] ?? [], $translations, $options, $seen);
        return $options ?: [['value' => 'default', 'name' => '默认线路', 'depth' => 0, 'disabled' => false]];
    }

    private function axisNowGeoIspTranslations(): array
    {
        static $translations = null;
        if (is_array($translations)) return $translations;

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'axisnow_geo_isp_zh_cn.json';
        $data = json_decode((string)@file_get_contents($path), true);
        $translations = is_array($data) ? $data : [];
        if (!is_array($translations)) $translations = [];
        return $translations;
    }

    private function translateGeoIspName(string $displayName, string $value, array $translations): string
    {
        foreach (['multilanguage.middle.geoIsp.', 'middle.geoIsp.', 'geoIsp.'] as $prefix) {
            if (str_starts_with($displayName, $prefix)) {
                $key = substr($displayName, strlen($prefix));
                if (isset($translations[$key]) && is_string($translations[$key])) {
                    return $translations[$key];
                }
            }
        }
        if (isset($translations[$displayName]) && is_string($translations[$displayName])) {
            return $translations[$displayName];
        }
        return $this->geoIspFallbackName($displayName, $value);
    }

    private function flattenGeoIspOptions(array $nodes, array $translations, array &$options, array &$seen, int $depth = 0): void
    {
        foreach ($nodes as $node) {
            $value = (string)($node['value'] ?? '');
            if ($value !== '' && !isset($seen[$value])) {
                $displayName = (string)($node['displayName'] ?? '');
                $options[] = [
                    'value' => $value,
                    'name' => $this->translateGeoIspName($displayName, $value, $translations),
                    'depth' => $depth,
                    'disabled' => !empty($node['disabled']),
                ];
                $seen[$value] = true;
            }
            $this->flattenGeoIspOptions($node['children'] ?? [], $translations, $options, $seen, $depth + 1);
        }
    }

    private function geoIspFallbackName(string $displayName, string $value): string
    {
        $key = str_starts_with($displayName, 'multilanguage.middle.geoIsp.')
            ? substr($displayName, strlen('multilanguage.middle.geoIsp.'))
            : '';
        $names = [
            'default' => '默认线路',
            'internal' => '中国大陆',
            'oversea' => '境外',
            'region' => '地域',
            'isp' => '运营商',
            'global' => '全球',
            'searchEngine' => '搜索引擎',
        ];
        return $names[$key] ?? $value;
    }

    private function providerOptions(array $rows, string $source): array
    {
        $options = [];
        foreach ($rows as $row) {
            $zones = [];
            foreach (($row['zones'] ?? []) as $zone) {
                $zoneValue = $zone['zone'] ?? ($zone['conf']['zone'] ?? '');
                $zones[] = ['uuid' => $zone['uuid'] ?? '', 'name' => $zone['name'] ?? $zoneValue, 'zone' => $zoneValue];
            }
            $options[] = [
                'uuid' => $row['uuid'] ?? '',
                'name' => $row['name'] ?? ($row['type'] ?? '-'),
                'type' => $row['type'] ?? '',
                'source' => $source,
                'zones' => $zones,
            ];
        }
        return $options;
    }

    private function simpleOptions(array $rows): array
    {
        return array_values(array_map(static fn($row) => [
            'uuid' => $row['uuid'] ?? '',
            'name' => $row['name'] ?? ($row['address'] ?? '-'),
            'description' => $row['description'] ?? '',
        ], $rows));
    }

    private function eipOptions(array $rows): array
    {
        $options = [];
        foreach ($rows as $row) {
            if (($row['data_origin'] ?? '') === 'subscribed'
                && !in_array(($row['subscription_status'] ?? ''), ['', 'active'], true)) {
                continue;
            }
            $address = $row['address'] ?? '-';
            $provider = trim((string)($row['provider_name'] ?? ''));
            $options[] = [
                'uuid' => $row['uuid'] ?? '',
                'name' => $provider !== '' ? $address . ' · ' . $provider : $address,
            ];
        }
        return $options;
    }

    private function indexNames(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!empty($row['uuid'])) $result[$row['uuid']] = $row['name'] ?? ($row['address'] ?? $row['uuid']);
        }
        return $result;
    }

    private function automationPoolInput(string $field, string $ruleType, $storedValue): ?array
    {
        $raw = trim((string)input('post.' . $field, '', 'trim'));
        if ($raw === '') return $this->decodeStoredPool($storedValue, null);
        $pool = json_decode($raw, true);
        if (!is_array($pool)) throw new Exception('地址池 JSON 格式无效');
        return $this->validateAutomationPool($pool, $ruleType);
    }

    private function validateAutomationPool(array $pool, string $ruleType): array
    {
        $mode = (string)($pool['mode'] ?? '');
        if ($mode === 'all_valid_eips') {
            if ($ruleType !== 'A') throw new Exception('CNAME 规则不支持全部有效 EIP 地址池');
            return ['mode' => 'all_valid_eips'];
        }
        if ($mode !== 'customize' || !is_array($pool['groups'] ?? null) || !$pool['groups']) {
            throw new Exception('地址池至少需要一个有效地址组');
        }
        if (count($pool['groups']) > 20) throw new Exception('单个地址池最多支持 20 个地址组');

        $groups = [];
        foreach ($pool['groups'] as $group) {
            if (!is_array($group)) throw new Exception('地址组格式无效');
            $type = (string)($group['type'] ?? '');
            if ($ruleType === 'CNAME' && $type !== 'domain') throw new Exception('CNAME 规则只能使用候选域名地址池');
            if ($ruleType === 'A' && !in_array($type, ['eip', 'eip_tag', 'ip'], true)) {
                throw new Exception('A 记录地址池类型无效');
            }
            if ($type === 'eip') {
                $values = $this->uuidList($group['eip_uuids'] ?? []);
                if (!$values) throw new Exception('指定 EIP 地址组不能为空');
                $group['eip_uuids'] = $values;
            } elseif ($type === 'eip_tag') {
                $values = $this->uuidList($group['tag_uuids'] ?? []);
                if (!$values) throw new Exception('EIP 标签地址组不能为空');
                $group['tag_uuids'] = $values;
            } elseif ($type === 'ip') {
                $values = $this->stringList($group['ips'] ?? []);
                foreach ($values as $value) {
                    if (!filter_var($value, FILTER_VALIDATE_IP)) throw new Exception('自定义 IP 格式无效：' . $value);
                }
                if (!$values) throw new Exception('自定义 IP 地址组不能为空');
                $group['ips'] = $values;
            } elseif ($type === 'domain') {
                $values = array_map([$this, 'hostName'], $this->stringList($group['domains'] ?? []));
                if (!$values) throw new Exception('CNAME 候选域名不能为空');
                $group['domains'] = $values;
            }
            $groups[] = $group;
        }
        $pool['mode'] = 'customize';
        $pool['groups'] = $groups;
        return $pool;
    }

    private function decodeStoredPool($value, ?array $fallback): ?array
    {
        if ($value === null || $value === '') return $fallback;
        $pool = is_array($value) ? $value : json_decode((string)$value, true);
        if (!is_array($pool) || empty($pool['mode'])) throw new Exception('已保存的地址池配置无效');
        return $pool;
    }

    private function samePool(array $left, array $right): bool
    {
        return AxisNowAutomationService::encodePool($this->sortPool($left))
            === AxisNowAutomationService::encodePool($this->sortPool($right));
    }

    private function sortPool(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) $item = $this->sortPool($item);
        }
        unset($item);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }

    private function stringList($value): array
    {
        if (is_array($value)) return array_values(array_unique(array_filter(array_map('trim', $value), static fn($item) => $item !== '')));
        $items = preg_split('/[\r\n,;\s]+/', trim((string)$value));
        return array_values(array_unique(array_filter(array_map('trim', $items ?: []), static fn($item) => $item !== '')));
    }

    private function addressEntries($value): array
    {
        $items = preg_split('/[\r\n,;]+/', trim((string)$value));
        return array_values(array_unique(array_filter(array_map('trim', $items ?: []), static fn($item) => $item !== '')));
    }

    private function expandEipAddresses(array $entries): array
    {
        $addresses = [];
        foreach ($entries as $entry) {
            if (filter_var($entry, FILTER_VALIDATE_IP)) {
                $addresses[] = $entry;
            } elseif (str_contains($entry, '/')) {
                $addresses = array_merge($addresses, $this->expandCidr($entry));
            } elseif (preg_match('/^(.+?)\s*-\s*(.+)$/', $entry, $matches)) {
                $addresses = array_merge($addresses, $this->expandIpRange(trim($matches[1]), trim($matches[2])));
            } else {
                throw new Exception('EIP 地址、范围或 CIDR 格式无效：' . $entry);
            }
            if (count($addresses) > 1000) throw new Exception('单次最多录入 1000 个 EIP，请缩小 IP 范围或 CIDR');
        }
        return array_values(array_unique($addresses));
    }

    private function expandCidr(string $cidr): array
    {
        [$address, $prefixText] = array_pad(explode('/', $cidr, 2), 2, '');
        $prefixText = trim($prefixText);
        $packed = @inet_pton(trim($address));
        if ($packed === false || !ctype_digit($prefixText)) throw new Exception('CIDR 格式无效：' . $cidr);
        $bits = strlen($packed) * 8;
        $prefix = intval($prefixText);
        if ($prefix < 0 || $prefix > $bits) throw new Exception('CIDR 前缀长度无效：' . $cidr);
        $hostBits = $bits - $prefix;
        if ($hostBits > 9) throw new Exception('CIDR 展开超过 512 个地址，请拆分后录入：' . $cidr);

        $bytes = array_values(unpack('C*', $packed));
        for ($bit = $prefix; $bit < $bits; $bit++) {
            $byteIndex = intdiv($bit, 8);
            $bytes[$byteIndex] &= ~(1 << (7 - ($bit % 8)));
        }
        $current = pack('C*', ...$bytes);
        $count = 1 << $hostBits;
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $result[] = inet_ntop($current);
            $current = $this->incrementPackedIp($current);
        }
        return $result;
    }

    private function expandIpRange(string $start, string $end): array
    {
        $current = @inet_pton($start);
        $last = @inet_pton($end);
        if ($current === false || $last === false || strlen($current) !== strlen($last) || strcmp($current, $last) > 0) {
            throw new Exception('IP 范围格式无效：' . $start . '-' . $end);
        }
        $result = [];
        while (strcmp($current, $last) <= 0) {
            $result[] = inet_ntop($current);
            if (count($result) > 1000) throw new Exception('单个 IP 范围最多包含 1000 个地址');
            if ($current === $last) break;
            $current = $this->incrementPackedIp($current);
        }
        return $result;
    }

    private function incrementPackedIp(string $packed): string
    {
        $bytes = array_values(unpack('C*', $packed));
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            if ($bytes[$i] < 255) {
                $bytes[$i]++;
                break;
            }
            $bytes[$i] = 0;
        }
        return pack('C*', ...$bytes);
    }

    private function uuidList($value): array
    {
        $result = [];
        foreach ((array)$value as $uuid) $result[] = $this->uuid((string)$uuid);
        return array_values(array_unique($result));
    }

    private function uuid(string $uuid): string
    {
        $uuid = trim($uuid);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            throw new Exception('UUID 格式无效');
        }
        return strtolower($uuid);
    }

    private function assertManagedDomainZone(AxisNowService $service, string $domain, string $providerUuid, string $zoneUuid): void
    {
        foreach ($service->listSystemDnsProviders() as $provider) {
            if (strtolower((string)($provider['uuid'] ?? '')) !== $providerUuid) continue;
            foreach (($provider['zones'] ?? []) as $zone) {
                if (strtolower((string)($zone['uuid'] ?? '')) !== $zoneUuid) continue;
                $suffix = trim((string)($zone['zone'] ?? ($zone['conf']['zone'] ?? ($zone['name'] ?? ''))), " \t\n\r\0\x0B.");
                if ($suffix === '') throw new Exception('AxisNow 托管域名后缀无效');
                $suffix = $this->hostName($suffix);
                if ($domain === $suffix || !str_ends_with($domain, '.' . $suffix)) {
                    throw new Exception('域名必须使用所选 AxisNow 托管域名后缀：' . $suffix);
                }
                return;
            }
            throw new Exception('所选托管域名后缀不属于当前 AxisNow DNS 提供商');
        }
        throw new Exception('所选 AxisNow DNS 提供商不存在');
    }

    private function domainName(string $domain): string
    {
        $domain = $this->hostName($domain);
        if (substr_count($domain, '.') < 2) {
            throw new Exception('请输入具体的完整域名，而不是根域名');
        }
        return $domain;
    }

    private function hostName(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $domain = convertDomainToAscii($domain);
        if (!is_string($domain) || !checkDomain($domain)) {
            throw new Exception('域名格式不正确');
        }
        return $domain;
    }

    private function addLog(string $account, string $action, string $data): void
    {
        Db::name('log')->insert([
            'uid' => request()->user['id'],
            'domain' => 'AxisNow/' . $account,
            'action' => $action,
            'data' => mb_substr($data, 0, 500),
            'addtime' => date('Y-m-d H:i:s'),
        ]);
    }
}
