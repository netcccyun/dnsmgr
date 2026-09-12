<?php

require dirname(__DIR__) . '/app/service/AxisNowAutomationService.php';

use app\service\AxisNowAutomationService;

date_default_timezone_set('Asia/Shanghai');

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assertSameValue(true, AxisNowAutomationService::isTideWindow('09:00', '18:00', strtotime('2026-09-10 12:00:00')), '普通潮汐时间窗口');
assertSameValue(false, AxisNowAutomationService::isTideWindow('09:00', '18:00', strtotime('2026-09-10 18:00:00')), '普通潮汐结束边界');
assertSameValue(true, AxisNowAutomationService::isTideWindow('22:00', '06:00', strtotime('2026-09-10 23:30:00')), '跨日潮汐前半段');
assertSameValue(true, AxisNowAutomationService::isTideWindow('22:00', '06:00', strtotime('2026-09-11 05:30:00')), '跨日潮汐后半段');
assertSameValue(false, AxisNowAutomationService::isTideWindow('22:00', '06:00', strtotime('2026-09-10 12:00:00')), '跨日潮汐窗口外');
assertSameValue(false, AxisNowAutomationService::isTideWindow('09:00', '09:00', strtotime('2026-09-10 12:00:00')), '相同时间边界的无效窗口必须关闭');

$entry = static fn(string $address, ?string $status): array => [
    'address' => $address,
    'stability_info' => $status === null ? [] : ['status' => $status],
];

assertSameValue('healthy', AxisNowAutomationService::healthState([
    'eips_count' => 2,
    'election_info' => ['list' => [$entry('192.0.2.1', 'available'), $entry('192.0.2.2', 'available')]],
]), '全部健康');
assertSameValue('partial', AxisNowAutomationService::healthState([
    'eips_count' => 2,
    'election_info' => ['list' => [$entry('192.0.2.1', 'unavailable'), $entry('192.0.2.2', 'available')]],
]), '部分失败');
assertSameValue('all_failed', AxisNowAutomationService::healthState([
    'eips_count' => 2,
    'election_info' => ['list' => [$entry('192.0.2.1', 'unavailable'), $entry('192.0.2.2', 'unavailable')]],
]), '全部失败');
assertSameValue('no_data', AxisNowAutomationService::healthState([
    'eips_count' => 2,
    'election_info' => ['list' => [$entry('192.0.2.1', null), $entry('192.0.2.2', 'unavailable')]],
]), '缺少探测状态必须视为无数据');
assertSameValue('no_data', AxisNowAutomationService::healthState([
    'eips_count' => 2,
    'election_info' => ['list' => [$entry('192.0.2.1', 'no_data'), $entry('192.0.2.2', 'unavailable')]],
]), '明确的无数据状态不得触发故障切换');
assertSameValue('no_data', AxisNowAutomationService::healthState([
    'eips_count' => 3,
    'election_info' => ['list' => [$entry('192.0.2.1', 'unavailable'), $entry('192.0.2.2', 'unavailable')]],
]), '地址数量不完整必须视为无数据');
assertSameValue('no_data', AxisNowAutomationService::healthState([
    'eips_count' => 2,
    'election_info' => ['list' => []],
]), '空列表必须视为无数据');
assertSameValue('no_data', AxisNowAutomationService::healthState([
    'eips_count' => 0,
    'election_info' => ['list' => [$entry('192.0.2.1', 'unavailable')]],
]), '未知地址数量必须视为无数据');
assertSameValue('healthy', AxisNowAutomationService::healthState([
    'eips_count' => 2,
    'election_info' => null,
], [
    ['target' => '192.0.2.1', 'status' => 'available'],
    ['target' => '192.0.2.2', 'status' => 'available'],
]), '应使用 AxisNow 探测状态接口');
assertSameValue('all_failed', AxisNowAutomationService::healthState([
    'eips_count' => 2,
    'election_info' => null,
], [
    ['target' => '192.0.2.1', 'status' => 'unavailable'],
    ['target' => '192.0.2.2', 'status' => 'unavailable'],
]), '探测状态接口的全部失败');
assertSameValue(null, AxisNowAutomationService::probeStatusesForRule([
    ['uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'list' => []],
], 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'), '不存在的规则探测状态');

echo "axisnow automation tests passed\n";
