<?php

namespace app\service;

use Exception;
use think\facade\Db;

/**
 * AxisNow 路由规则自动调度。
 *
 * 潮汐调度复用系统 cron；故障切换只在所有候选地址均有明确的
 * unavailable 探测结果时触发。切换到故障池后必须由用户手动恢复。
 */
class AxisNowAutomationService
{
    private const TABLE = 'axisnow_rule_automation';
    private const LOG_TABLE = 'axisnow_rule_automation_log';
    private const LOCK_SECONDS = 120;

    public function execute(): bool
    {
        $rows = Db::name(self::TABLE)
            ->where('failover_state', '<>', 'switched')
            ->where(function ($query) {
                $query->where('tide_enabled', 1)
                    ->whereOr('failover_enabled', 1)
                    ->whereOr('active_pool', '<>', 'primary');
            })
            ->order('id', 'asc')
            ->select();
        $handled = false;
        foreach ($rows as $row) {
            if (!$this->claim((int)$row['id'])) continue;
            $handled = true;
            try {
                $current = Db::name(self::TABLE)->where('id', $row['id'])->find();
                if ($current) $this->process($current);
            } catch (\Throwable $e) {
                $this->recordError((int)$row['id'], $e->getMessage());
            } finally {
                Db::name(self::TABLE)->where('id', $row['id'])->update(['lock_until' => 0]);
            }
        }
        return $handled;
    }

    public function restore(int $accountId, string $ruleUuid): void
    {
        $row = Db::name(self::TABLE)
            ->where('account_id', $accountId)
            ->where('rule_uuid', $ruleUuid)
            ->find();
        if (!$row) throw new Exception('该规则没有自动调度配置');
        if (!$this->claim((int)$row['id'])) throw new Exception('该规则正在执行自动调度，请稍后重试');
        try {
            $account = $this->account($accountId);
            $service = $this->service($account);
            $rule = $service->getRule($ruleUuid);
            if (($rule['dns_domain_uuid'] ?? '') !== ($row['domain_uuid'] ?? '')) {
                throw new Exception('AxisNow 路由规则与调度域名不匹配');
            }
            $pool = $this->decodePool($row['primary_pool'] ?? null, '主地址池');
            Db::name(self::TABLE)->where('id', $row['id'])->update([
                'active_pool' => 'pending_primary',
                'failover_state' => 'restoring',
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->applyPool($service, $rule, $pool);
            $now = time();
            Db::name(self::TABLE)->where('id', $row['id'])->update([
                'active_pool' => 'primary',
                'failover_state' => 'armed',
                'fail_count' => 0,
                'next_check_at' => $now,
                'last_switch_at' => $now,
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);
            $this->addLog($row, 'manual_restore', 'success', '已恢复主地址池并重新布防故障切换');
            $this->addSystemLog($account, $rule, '恢复AxisNow主地址池', '手动恢复并重新布防');
        } catch (\Throwable $e) {
            $this->recordError((int)$row['id'], $e->getMessage());
            throw $e;
        } finally {
            Db::name(self::TABLE)->where('id', $row['id'])->update(['lock_until' => 0]);
        }
    }

    public static function syncActivePool(int $accountId, string $ruleUuid, array $pool): void
    {
        $row = Db::name(self::TABLE)
            ->where('account_id', $accountId)
            ->where('rule_uuid', $ruleUuid)
            ->find();
        if (!$row) return;
        $field = match ((string)($row['active_pool'] ?? 'primary')) {
            'tide', 'pending_tide' => 'tide_pool',
            'failover', 'pending_failover' => 'failover_pool',
            default => 'primary_pool',
        };
        Db::name(self::TABLE)->where('id', $row['id'])->update([
            $field => self::encodePool($pool),
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function removeRule(int $accountId, string $ruleUuid): void
    {
        $ids = Db::name(self::TABLE)
            ->where('account_id', $accountId)
            ->where('rule_uuid', $ruleUuid)
            ->column('id');
        if ($ids) Db::name(self::LOG_TABLE)->whereIn('automation_id', $ids)->delete();
        Db::name(self::TABLE)
            ->where('account_id', $accountId)
            ->where('rule_uuid', $ruleUuid)
            ->delete();
    }

    public static function removeDomain(int $accountId, string $domainUuid): void
    {
        $ids = Db::name(self::TABLE)
            ->where('account_id', $accountId)
            ->where('domain_uuid', $domainUuid)
            ->column('id');
        if ($ids) Db::name(self::LOG_TABLE)->whereIn('automation_id', $ids)->delete();
        Db::name(self::TABLE)
            ->where('account_id', $accountId)
            ->where('domain_uuid', $domainUuid)
            ->delete();
    }

    public static function removeAccount(int $accountId): void
    {
        $ids = Db::name(self::TABLE)->where('account_id', $accountId)->column('id');
        if ($ids) Db::name(self::LOG_TABLE)->whereIn('automation_id', $ids)->delete();
        Db::name(self::TABLE)->where('account_id', $accountId)->delete();
    }

    public static function isTideWindow(string $start, string $end, ?int $timestamp = null): bool
    {
        $current = date('H:i', $timestamp ?? time());
        // Equal bounds are rejected when saving a configuration. Treat an
        // invalid legacy row as disabled rather than accidentally enabling it
        // for the entire day.
        if ($start === $end) return false;
        if ($start < $end) return $current >= $start && $current < $end;
        return $current >= $start || $current < $end;
    }

    /**
     * Return the task list for one rule from the edge-probe status response.
     * A missing rule is different from a rule with an empty list: both are
     * incomplete probe data, but the distinction lets callers fall back to
     * the legacy election_info shape when an older AxisNow API is in use.
     */
    public static function probeStatusesForRule(array $rows, string $ruleUuid): ?array
    {
        $ruleUuid = strtolower(trim($ruleUuid));
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $uuid = strtolower(trim((string)($row['uuid'] ?? $row['dns_rule_uuid'] ?? '')));
            if ($uuid !== '' && $uuid === $ruleUuid) {
                return is_array($row['list'] ?? null) ? $row['list'] : [];
            }
        }
        return null;
    }

    /**
     * @return string healthy|partial|all_failed|no_data
     */
    public static function healthState(array $rule, ?array $probeStatuses = null): string
    {
        if ($probeStatuses !== null) {
            $list = [];
            foreach ($probeStatuses as $probe) {
                if (!is_array($probe)) {
                    $list[] = $probe;
                    continue;
                }
                $list[] = [
                    'address' => $probe['target'] ?? '',
                    'stability_info' => ['status' => $probe['status'] ?? ''],
                ];
            }
        } else {
            $list = $rule['election_info']['list'] ?? [];
        }
        if (!is_array($list) || !$list) return 'no_data';
        $expected = (int)($rule['eips_count'] ?? 0);
        // A missing/zero candidate count is not evidence that every address
        // failed. Without a known count, the probe result is incomplete.
        if ($expected <= 0) return 'no_data';
        $seen = [];
        foreach ($list as $entry) {
            if (!is_array($entry)) return 'no_data';
            $address = strtolower(rtrim(trim((string)($entry['address'] ?? '')), '.'));
            if ($address === '') return 'no_data';
            $stability = $entry['stability_info'] ?? null;
            if (!is_array($stability)) return 'no_data';
            $status = strtolower(trim((string)($stability['status'] ?? '')));
            if ($status === '') return 'no_data';
            if (!in_array($status, ['available', 'unavailable'], true)) return 'no_data';
            $seen[$address] = $status;
        }
        if ($expected > 0 && count($seen) < $expected) return 'no_data';
        $failed = count(array_filter($seen, static fn($status) => $status === 'unavailable'));
        if ($failed === count($seen)) return 'all_failed';
        return $failed > 0 ? 'partial' : 'healthy';
    }

    public static function encodePool(array $pool): string
    {
        $json = json_encode($pool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new Exception('地址池序列化失败');
        return $json;
    }

    private function process(array $row): void
    {
        $account = $this->account((int)$row['account_id']);
        $service = $this->service($account);
        $rule = $service->getRule((string)$row['rule_uuid']);
        if (($rule['dns_domain_uuid'] ?? '') !== ($row['domain_uuid'] ?? '')) {
            throw new Exception('AxisNow 路由规则与调度域名不匹配');
        }
        $currentPool = $rule['action']['conf']['address_pool'] ?? null;
        if (!is_array($currentPool)) throw new Exception('AxisNow 路由规则缺少地址池');
        $primaryPool = $this->decodePool($row['primary_pool'] ?? null, '主地址池');
        $tidePool = !empty($row['tide_pool']) ? $this->decodePool($row['tide_pool'], '潮汐地址池') : null;
        $failoverPool = !empty($row['failover_pool']) ? $this->decodePool($row['failover_pool'], '故障备份地址池') : null;
        $activePool = (string)($row['active_pool'] ?? 'primary');

        if (str_starts_with($activePool, 'pending_')) {
            $target = substr($activePool, strlen('pending_'));
            $targetPool = match ($target) {
                'primary' => $primaryPool,
                'tide' => $tidePool,
                'failover' => $failoverPool,
                default => null,
            };
            if (!$targetPool) throw new Exception('待确认的自动调度地址池不存在');
            $this->finishPendingSwitch($row, $account, $service, $rule, $currentPool, $targetPool, $target);
            return;
        }

        if (($row['failover_state'] ?? 'armed') === 'switched' || $activePool === 'failover') return;

        if ($activePool === 'primary' && $this->poolHash($currentPool) !== $this->poolHash($primaryPool)) {
            $primaryPool = $currentPool;
            Db::name(self::TABLE)->where('id', $row['id'])->update([
                'primary_pool' => self::encodePool($primaryPool),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->addLog($row, 'sync_primary', 'success', '检测到外部修改，已同步新的主地址池快照');
        } elseif ($activePool === 'tide' && $tidePool && $this->poolHash($currentPool) !== $this->poolHash($tidePool)) {
            $tidePool = $currentPool;
            Db::name(self::TABLE)->where('id', $row['id'])->update([
                'tide_pool' => self::encodePool($tidePool),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->addLog($row, 'sync_tide', 'success', '检测到外部修改，已同步新的潮汐地址池快照');
        }

        if (!empty($row['failover_enabled']) && $failoverPool && (int)$row['next_check_at'] <= time()) {
            $canProbe = strtoupper((string)($rule['type'] ?? '')) === 'A'
                && !empty(array_filter((array)($rule['action']['conf']['edge_probe_template_uuid'] ?? [])));
            $probeStatuses = null;
            if ($canProbe) {
                try {
                    $probeRows = $service->listProbeTaskStatusesByRuleUuids([(string)$row['rule_uuid']]);
                    $probeStatuses = self::probeStatusesForRule($probeRows, (string)$row['rule_uuid']);
                } catch (\Throwable $e) {
                    // An unavailable status endpoint must never be interpreted
                    // as a failed probe and must not trigger failover.
                }
            }
            $state = $canProbe ? self::healthState($rule, $probeStatuses) : 'no_data';
            $now = time();
            $updates = [
                'last_check_at' => $now,
                'last_health_state' => $state,
                'next_check_at' => $now + max(60, (int)$row['check_interval']),
                'updated_at' => date('Y-m-d H:i:s', $now),
            ];
            if ($state === 'all_failed') {
                $failCount = (int)$row['fail_count'] + 1;
                $updates['fail_count'] = $failCount;
                if ($failCount >= max(1, (int)$row['failure_threshold'])) {
                    $updates['active_pool'] = 'pending_failover';
                    $updates['failover_state'] = 'switching';
                    $updates['last_error'] = null;
                    Db::name(self::TABLE)->where('id', $row['id'])->update($updates);
                    $this->applyPool($service, $rule, $failoverPool);
                    $updates['active_pool'] = 'failover';
                    $updates['failover_state'] = 'switched';
                    $updates['fail_count'] = 0;
                    $updates['last_switch_at'] = $now;
                    $updates['last_error'] = null;
                    Db::name(self::TABLE)->where('id', $row['id'])->update($updates);
                    $this->addLog($row, 'failover', 'success', '所有候选地址连续探测失败，已切换故障备份地址池');
                    $this->addSystemLog($account, $rule, 'AxisNow故障切换', '所有候选地址探测失败，已一次性切换到备份地址池');
                    return;
                }
            } else {
                $updates['fail_count'] = 0;
            }
            Db::name(self::TABLE)->where('id', $row['id'])->update($updates);
        }

        if (empty($row['tide_enabled']) || !$tidePool) {
            if ($activePool === 'tide') {
                $this->switchPool($row, $account, $service, $rule, $primaryPool, 'primary', '潮汐调度已停用，恢复主地址池');
            }
            return;
        }

        $inside = self::isTideWindow((string)$row['tide_start'], (string)$row['tide_end']);
        $target = $inside ? 'tide' : 'primary';
        $targetPool = $inside ? $tidePool : $primaryPool;
        if ($activePool === $target) return;
        $this->switchPool(
            $row,
            $account,
            $service,
            $rule,
            $targetPool,
            $target,
            $inside ? '进入潮汐时间段，启用潮汐地址池' : '离开潮汐时间段，恢复主地址池'
        );
    }

    private function switchPool(array $row, array $account, AxisNowService $service, array $rule, array $pool, string $target, string $message): void
    {
        $now = time();
        Db::name(self::TABLE)->where('id', $row['id'])->update([
            'active_pool' => 'pending_' . $target,
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ]);
        $this->applyPool($service, $rule, $pool);
        Db::name(self::TABLE)->where('id', $row['id'])->update([
            'active_pool' => $target,
            'last_switch_at' => $now,
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ]);
        $this->addLog($row, 'tide_' . $target, 'success', $message);
        $this->addSystemLog($account, $rule, 'AxisNow潮汐调度', $message);
    }

    private function finishPendingSwitch(
        array $row,
        array $account,
        AxisNowService $service,
        array $rule,
        array $currentPool,
        array $targetPool,
        string $target
    ): void
    {
        if (!in_array($target, ['primary', 'tide', 'failover'], true)) {
            throw new Exception('待确认的自动调度状态无效');
        }
        if ($this->poolHash($currentPool) !== $this->poolHash($targetPool)) {
            $this->applyPool($service, $rule, $targetPool);
        }
        $now = time();
        $updates = [
            'active_pool' => $target,
            'last_switch_at' => $now,
            'last_error' => null,
            'updated_at' => date('Y-m-d H:i:s', $now),
        ];
        if ($target === 'failover') {
            $updates['failover_state'] = 'switched';
            $updates['fail_count'] = 0;
        } elseif (($row['failover_state'] ?? '') === 'restoring') {
            $updates['failover_state'] = 'armed';
            $updates['fail_count'] = 0;
            $updates['next_check_at'] = $now;
        }
        Db::name(self::TABLE)->where('id', $row['id'])->update($updates);
        $message = match ($target) {
            'failover' => '已确认切换到故障备份地址池，等待手动恢复',
            'tide' => '已确认切换到潮汐地址池',
            default => '已确认恢复主地址池',
        };
        $this->addLog($row, 'confirm_' . $target, 'success', $message);
        $this->addSystemLog($account, $rule, 'AxisNow自动调度确认', $message);
    }

    private function applyPool(AxisNowService $service, array $rule, array $pool): void
    {
        // 只在切换前重新获取一次，避免用较早的规则快照覆盖
        // 用户刚修改的状态、TTL、选取策略或监控模板。
        $ruleUuid = (string)($rule['uuid'] ?? '');
        if ($ruleUuid === '') throw new Exception('AxisNow 路由规则 UUID 缺失');
        $rule = $service->getRule($ruleUuid);
        $payload = [];
        foreach (['domain', 'type', 'geo_isp', 'name', 'description', 'dns_domain_uuid', 'action', 'status'] as $key) {
            if (array_key_exists($key, $rule)) $payload[$key] = $rule[$key];
        }
        if (!isset($payload['action']['conf']) || !is_array($payload['action']['conf'])) {
            throw new Exception('AxisNow 路由规则配置无效');
        }
        $payload['action']['conf']['address_pool'] = $pool;
        $service->updateRule($ruleUuid, $payload);
    }

    private function claim(int $id): bool
    {
        $now = time();
        return Db::name(self::TABLE)
            ->where('id', $id)
            ->where('lock_until', '<=', $now)
            ->update(['lock_until' => $now + self::LOCK_SECONDS]) === 1;
    }

    private function account(int $id): array
    {
        $account = Db::name('account')->where('id', $id)->where('type', 'axisnow')->find();
        if (!$account) throw new Exception('AxisNow 账号不存在');
        return $account;
    }

    private function service(array $account): AxisNowService
    {
        $config = json_decode((string)($account['config'] ?? ''), true);
        if (!is_array($config)) throw new Exception('AxisNow 账号配置无效');
        return new AxisNowService($config);
    }

    private function decodePool($value, string $name): array
    {
        $pool = is_array($value) ? $value : json_decode((string)$value, true);
        if (!is_array($pool) || empty($pool['mode'])) throw new Exception($name . '配置无效');
        return $pool;
    }

    private function poolHash(array $pool): string
    {
        return hash('sha256', self::encodePool($this->sortRecursive($pool)));
    }

    private function sortRecursive(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) $item = $this->sortRecursive($item);
        }
        unset($item);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }

    private function recordError(int $id, string $message): void
    {
        $message = mb_substr($message, 0, 500);
        $row = Db::name(self::TABLE)->where('id', $id)->find();
        Db::name(self::TABLE)->where('id', $id)->update([
            'last_error' => $message,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($row) $this->addLog($row, 'execute', 'failed', $message);
    }

    private function addLog(array $row, string $action, string $status, string $message): void
    {
        Db::name(self::LOG_TABLE)->insert([
            'automation_id' => $row['id'],
            'account_id' => $row['account_id'],
            'rule_uuid' => $row['rule_uuid'],
            'action' => $action,
            'status' => $status,
            'message' => mb_substr($message, 0, 500),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function addSystemLog(array $account, array $rule, string $action, string $message): void
    {
        $name = trim((string)($account['remark'] ?? '')) ?: (string)($account['name'] ?? $account['id']);
        $line = (string)($rule['geo_isp'] ?? $rule['uuid'] ?? '');
        Db::name('log')->insert([
            'uid' => 0,
            'domain' => 'AxisNow/' . $name,
            'action' => $action,
            'data' => mb_substr($line . ' / ' . $message, 0, 500),
            'addtime' => date('Y-m-d H:i:s'),
        ]);
    }
}
