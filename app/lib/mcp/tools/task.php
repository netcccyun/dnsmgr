<?php

use app\controller\Dmonitor;
use app\controller\Optimizeip;
use app\controller\Schedule;
use app\lib\mcp\McpException;
use app\lib\mcp\ToolRegistry as T;
use think\facade\Db;

$recordInfoDesc = '所选解析记录信息对象，如 {"Value":"1.2.3.4","Line":"default","LineName":"默认","TTL":600}，取自 record_query subdomain 的返回';

return [
    [
        'name' => 'dmonitor_query',
        'title' => '查询容灾切换',
        'description' => '查询容灾切换（健康检测与自动切换解析）策略、运行状态和切换日志。',
        'readOnly' => true,
        'properties' => array_merge([
            'id' => T::int('策略ID（info/logs 必填）'),
            'kw' => T::str('list：搜索关键词'),
            'type' => T::int('list：搜索字段，1=域名/主机记录，2=记录ID，3=主地址，4=备用地址，5=备注'),
            'status' => T::str('list：按健康状态筛选，0=正常，1=异常', ['0', '1']),
            'log_action' => T::int('logs：按动作筛选，1=异常切换，2=恢复'),
        ], T::paging()),
        'actions' => [
            'overview' => [
                'desc' => '容灾监控进程运行状态与近24小时切换统计',
                'handler' => function ($args) {
                    $since = date('Y-m-d H:i:s', strtotime('-1 days'));
                    $run_time = config_get('run_time', null, true);
                    return ['code' => 0, 'data' => [
                        'run_state' => $run_time && time() - strtotime($run_time) <= 10 ? 1 : 0,
                        'run_time' => $run_time,
                        'run_count' => config_get('run_count', null, true) ?? 0,
                        'run_error' => config_get('run_error', null, true),
                        'switch_count' => Db::name('dmlog')->where('date', '>=', $since)->count(),
                        'fail_count' => Db::name('dmlog')->where('date', '>=', $since)->where('action', 1)->count(),
                        'swoole' => extension_loaded('swoole'),
                    ]];
                },
            ],
            'list' => [
                'desc' => '容灾切换策略列表',
                'call' => [Dmonitor::class, 'task_data'],
                'defaults' => T::pagingDefaults(),
            ],
            'info' => [
                'desc' => '策略详情与近24小时切换统计',
                'handler' => function ($args) {
                    T::requireArgs($args, ['id']);
                    $id = intval($args['id']);
                    $task = Db::name('dmtask')->where('id', $id)->find();
                    if (!$task) throw new McpException('切换策略不存在');
                    $since = date('Y-m-d H:i:s', strtotime('-1 days'));
                    $task['switch_count'] = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', $since)->count();
                    $task['fail_count'] = Db::name('dmlog')->where('taskid', $id)->where('date', '>=', $since)->where('action', 1)->count();
                    return ['code' => 0, 'data' => $task];
                },
            ],
            'logs' => [
                'desc' => '策略的切换日志',
                'call' => [Dmonitor::class, 'tasklog_data'],
                'route' => ['id' => '@id'],
                'defaults' => T::pagingDefaults(),
                'before' => function ($args) {
                    if (isset($args['log_action'])) $args['action'] = $args['log_action'];
                    unset($args['log_action']);
                    return $args;
                },
            ],
        ],
    ],
    [
        'name' => 'dmonitor_manage',
        'title' => '管理容灾切换',
        'description' => "添加、修改、删除、启停容灾切换策略。添加前先用 record_query(action=subdomain) 获取目标解析记录的 RecordId 与记录信息。\n切换类型 type：0=无操作（仅监控），1=暂停解析，2=切换备用解析，3=条件开启解析。检测方式 checktype：0=PING，1=TCP，2=HTTP(S)。",
        'destructive' => true,
        'properties' => [
            'id' => T::int('策略ID（edit/set_active/del 必填）'),
            'ids' => T::ids('batch：策略ID列表'),
            'did' => T::int('域名ID'),
            'rr' => T::str('主机记录'),
            'recordid' => T::str('解析记录ID'),
            'recordinfo' => T::obj($recordInfoDesc),
            'main_value' => T::str('主记录值（即所选记录的值）'),
            'type' => T::int('切换类型：0=无操作，1=暂停解析，2=切换备用解析，3=条件开启解析'),
            'backup_value' => T::str('type=2：备用解析地址（IP或CNAME）'),
            'checktype' => T::int('检测方式：0=PING，1=TCP，2=HTTP(S)'),
            'checkurl' => T::str('checktype=2：检测URL；checktype=0/1：检测IP（留空为记录值）'),
            'tcpport' => T::int('checktype=1：TCP端口'),
            'frequency' => T::int('检测间隔（秒），默认5'),
            'cycle' => T::int('连续失败几次后切换，默认3；type=3 时为同域名正常记录数<=几条时开启'),
            'timeout' => T::int('最大超时时间（秒），默认2，不能大于检测间隔'),
            'proxy' => T::bool('HTTP检测是否使用代理'),
            'cdn' => T::bool('type=2：切换时同时开启Cloudflare代理'),
            'remark' => T::str('备注'),
            'active' => T::bool('set_active：是否启用'),
            'act' => T::str('batch：操作类型', ['delete', 'retry', 'open', 'close']),
            'days' => T::int('clean_logs：清理多少天前的日志'),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加策略（did、rr、recordid、recordinfo、main_value 必填）',
                'call' => [Dmonitor::class, 'task_op'],
                'route' => ['action' => 'add'],
                'defaults' => ['type' => 1, 'checktype' => 1, 'tcpport' => 80, 'frequency' => 5, 'cycle' => 3, 'timeout' => 2, 'proxy' => 0, 'cdn' => 0],
                'json' => ['recordinfo'],
            ],
            'edit' => [
                'desc' => '修改策略（id 必填，未传字段保持不变）',
                'call' => [Dmonitor::class, 'task_op'],
                'route' => ['action' => 'edit'],
                'json' => ['recordinfo'],
                'before' => T::prefill('dmtask', ['did', 'rr', 'recordid', 'type', 'main_value', 'backup_value', 'checktype', 'checkurl', 'tcpport', 'frequency', 'cycle', 'timeout', 'proxy', 'cdn', 'remark', 'recordinfo']),
            ],
            'set_active' => [
                'desc' => '启用或停用策略（id、active 必填）',
                'call' => [Dmonitor::class, 'task_op'],
                'route' => ['action' => 'setactive'],
            ],
            'del' => [
                'desc' => '删除策略（id 必填）',
                'call' => [Dmonitor::class, 'task_op'],
                'route' => ['action' => 'del'],
            ],
            'batch' => [
                'desc' => '批量操作（ids、act 必填）',
                'call' => [Dmonitor::class, 'task_op'],
                'route' => ['action' => 'operation'],
            ],
            'clean_logs' => [
                'desc' => '清理指定天数之前的切换日志（days 必填）',
                'call' => [Dmonitor::class, 'clean'],
            ],
        ],
    ],
    [
        'name' => 'optimizeip_query',
        'title' => '查询优选IP',
        'description' => '查询优选IP任务（为域名自动解析CDN优选IP）列表，或查询优选IP接口积分余额。',
        'readOnly' => true,
        'properties' => array_merge([
            'kw' => T::str('list：搜索关键词'),
            'type' => T::int('list：搜索字段，1=域名/主机记录，2=备注'),
            'status' => T::str('list：按状态筛选，0=未运行，1=成功，2=失败', ['0', '1', '2']),
        ], T::paging()),
        'actions' => [
            'list' => [
                'desc' => '优选IP任务列表',
                'call' => [Optimizeip::class, 'opiplist_data'],
                'defaults' => T::pagingDefaults(),
            ],
            'api_balance' => [
                'desc' => '查询当前配置的优选IP接口积分余额',
                'call' => [Optimizeip::class, 'queryapi'],
                'before' => function ($args) {
                    return [
                        'optimize_ip_api' => config_get('optimize_ip_api', 0),
                        'optimize_ip_key' => config_get('optimize_ip_key', ''),
                    ];
                },
            ],
        ],
    ],
    [
        'name' => 'optimizeip_manage',
        'title' => '管理优选IP',
        'description' => '添加、修改、删除、启停、立即执行优选IP任务。仅支持非 Cloudflare 的域名。',
        'destructive' => true,
        'properties' => [
            'id' => T::int('任务ID（add 以外必填）'),
            'did' => T::int('域名ID'),
            'rr' => T::str('主机记录'),
            'type' => T::int('解析线路类型：0=电信/联通/移动线路，1=默认/联通/移动线路'),
            'cdn_type' => T::int('CDN运营商：1=CloudFlare，2=CloudFront，4=EdgeOne'),
            'ip_type' => T::str('IP类型，v4、v6 或 v4,v6'),
            'recordnum' => T::int('每个线路解析的IP数量（1-50），默认2'),
            'ttl' => T::int('TTL，默认600'),
            'remark' => T::str('备注'),
            'active' => T::bool('set_active：是否启用'),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加任务（did、rr 必填）',
                'call' => [Optimizeip::class, 'opipform'],
                'route' => ['action' => 'add'],
                'defaults' => ['type' => 0, 'cdn_type' => 1, 'ip_type' => 'v4', 'recordnum' => 2, 'ttl' => 600],
            ],
            'edit' => [
                'desc' => '修改任务（id 必填，未传字段保持不变）',
                'call' => [Optimizeip::class, 'opipform'],
                'route' => ['action' => 'edit'],
                'before' => T::prefill('optimizeip', ['did', 'rr', 'type', 'ip_type', 'cdn_type', 'recordnum', 'ttl', 'remark']),
            ],
            'set_active' => [
                'desc' => '启用或停用任务（id、active 必填）',
                'call' => [Optimizeip::class, 'opipform'],
                'route' => ['action' => 'setactive'],
            ],
            'del' => [
                'desc' => '删除任务（id 必填）',
                'call' => [Optimizeip::class, 'opipform'],
                'route' => ['action' => 'del'],
            ],
            'run' => [
                'desc' => '立即执行一次优选任务（id 必填）',
                'call' => [Optimizeip::class, 'opipform'],
                'route' => ['action' => 'run'],
            ],
        ],
    ],
    [
        'name' => 'schedule_query',
        'title' => '查询定时切换',
        'description' => '查询定时切换（按时间自动修改/启用/暂停/删除解析）任务列表。',
        'readOnly' => true,
        'properties' => array_merge([
            'kw' => T::str('搜索关键词'),
            'type' => T::int('搜索字段，1=域名/主机记录，2=记录ID，3=记录值，4=备注'),
            'stype' => T::str('按执行方式筛选，0=单次，1=周期', ['0', '1']),
        ], T::paging()),
        'actions' => [
            'list' => [
                'desc' => '定时切换任务列表',
                'call' => [Schedule::class, 'stask_data'],
                'defaults' => T::pagingDefaults(),
            ],
        ],
    ],
    [
        'name' => 'schedule_manage',
        'title' => '管理定时切换',
        'description' => "添加、修改、删除、启停定时切换任务。添加前先用 record_query(action=subdomain) 获取目标解析记录的 RecordId 与记录信息。\n执行方式 type：0=单次执行（switchtime 为 Y-m-dTH:i 格式的日期时间），1=周期执行（cycle：0=每天，1=每周，2=每月；switchdate：每周时为0-6（0=周日），每月时为1-31；switchtime 为 H:i）。\n切换操作 switchtype：0=修改解析（需 value），1=启用解析，2=暂停解析，3=删除解析（仅单次）。",
        'destructive' => true,
        'properties' => [
            'id' => T::int('任务ID（add 以外必填）'),
            'ids' => T::ids('batch：任务ID列表'),
            'did' => T::int('域名ID'),
            'rr' => T::str('主机记录'),
            'recordid' => T::str('解析记录ID'),
            'recordinfo' => T::obj($recordInfoDesc),
            'type' => T::int('执行方式：0=单次，1=周期'),
            'cycle' => T::int('周期：0=每天，1=每周，2=每月'),
            'switchdate' => T::str('周期日期：每周为0-6，每月为1-31'),
            'switchtime' => T::str('执行时间'),
            'switchtype' => T::int('切换操作：0=修改解析，1=启用，2=暂停，3=删除'),
            'value' => T::str('switchtype=0：新的记录值'),
            'line' => T::str('switchtype=0 且为Cloudflare：空=不修改，0=仅DNS，1=代理'),
            'remark' => T::str('备注'),
            'active' => T::bool('set_active：是否启用'),
            'act' => T::str('batch：操作类型', ['delete', 'open', 'close']),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加任务（did、rr、recordid、recordinfo、type、switchtype、switchtime 必填）',
                'call' => [Schedule::class, 'stask_op'],
                'route' => ['action' => 'add'],
                'defaults' => ['type' => 0, 'cycle' => 0, 'switchtype' => 0],
                'json' => ['recordinfo'],
            ],
            'edit' => [
                'desc' => '修改任务（id 必填，未传字段保持不变）',
                'call' => [Schedule::class, 'stask_op'],
                'route' => ['action' => 'edit'],
                'json' => ['recordinfo'],
                'before' => T::prefill('sctask', ['did', 'rr', 'recordid', 'type', 'cycle', 'switchtype', 'switchdate', 'switchtime', 'value', 'line', 'remark', 'recordinfo']),
            ],
            'set_active' => [
                'desc' => '启用或停用任务（id、active 必填）',
                'call' => [Schedule::class, 'stask_op'],
                'route' => ['action' => 'setactive'],
            ],
            'del' => [
                'desc' => '删除任务（id 必填）',
                'call' => [Schedule::class, 'stask_op'],
                'route' => ['action' => 'del'],
            ],
            'batch' => [
                'desc' => '批量操作（ids、act 必填）',
                'call' => [Schedule::class, 'stask_op'],
                'route' => ['action' => 'operation'],
            ],
        ],
    ],
];
