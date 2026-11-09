<?php

use app\controller\Domain;
use app\lib\mcp\ToolRegistry as T;

$recordObject = [
    'type' => 'object',
    'description' => '解析记录对象（取自 record_query list 的返回），需包含 RecordId、Name、Type、Value、Line、TTL、MX、Weight、Remark',
    'properties' => [
        'RecordId' => ['type' => 'string'],
        'Name' => ['type' => 'string'],
        'Type' => ['type' => 'string'],
        'Value' => ['type' => 'string'],
        'Line' => ['type' => 'string'],
        'TTL' => ['type' => 'integer'],
    ],
    'required' => ['RecordId'],
];

$withRecords = function ($args) {
    $args['recordinfo'] = T::normalizeRecords($args['records'] ?? null);
    unset($args['records']);
    return $args;
};

return [
    [
        'name' => 'record_query',
        'title' => '查询解析记录',
        'description' => '查询某个域名的DNS解析记录、解析日志、记录分组、权重配置，或检测解析是否生效。domain_id 为系统内的域名ID（可通过 domain_query list 获取）。',
        'readOnly' => true,
        'properties' => array_merge([
            'domain_id' => T::int('域名ID'),
            'keyword' => T::str('list/weight_list：关键词搜索'),
            'subdomain' => T::str('list：按主机记录精确筛选，如 www、@'),
            'value' => T::str('list：按记录值筛选；check：期望的记录值'),
            'type' => T::str('list：按记录类型筛选；check：记录类型，如 A、CNAME'),
            'line' => T::str('list：按线路ID筛选'),
            'status' => T::str('list：按状态筛选，1=启用，0=暂停', ['0', '1']),
            'rr' => T::str('subdomain：主机记录，如 www、@'),
            'recordid' => T::str('check：解析记录ID'),
            'name' => T::str('check：主机记录'),
        ], T::paging()),
        'required' => ['domain_id'],
        'actions' => [
            'list' => [
                'desc' => '解析记录列表（支持分页和筛选）',
                'level' => 1,
                'call' => [Domain::class, 'record_data'],
                'route' => ['id' => '@domain_id'],
                'defaults' => T::pagingDefaults(),
            ],
            'subdomain' => [
                'desc' => '获取指定主机记录（rr）下的全部解析记录',
                'level' => 1,
                'call' => [Domain::class, 'record_list'],
                'before' => function ($args) {
                    T::requireArgs($args, ['rr']);
                    $args['id'] = $args['domain_id'];
                    return $args;
                },
            ],
            'log' => [
                'desc' => 'DNS服务商侧的解析操作日志（部分服务商支持）',
                'level' => 1,
                'call' => [Domain::class, 'record_log'],
                'route' => ['id' => '@domain_id'],
                'defaults' => T::pagingDefaults(),
            ],
            'groups' => [
                'desc' => '解析记录分组列表（仅阿里云、腾讯云）',
                'level' => 1,
                'call' => [Domain::class, 'record_groups'],
                'route' => ['id' => '@domain_id'],
            ],
            'check' => [
                'desc' => '检测解析记录是否已生效（recordid、name、type、value 必填）',
                'level' => 1,
                'call' => [Domain::class, 'record_check'],
                'route' => ['id' => '@domain_id'],
            ],
            'weight_list' => [
                'desc' => '权重配置的子域名列表（仅阿里云）',
                'level' => 1,
                'call' => [Domain::class, 'weight_data'],
                'route' => ['id' => '@domain_id'],
                'defaults' => T::pagingDefaults(),
            ],
        ],
    ],
    [
        'name' => 'record_manage',
        'title' => '管理解析记录',
        'description' => "添加、修改、删除、启用/暂停DNS解析记录，以及批量操作和权重设置。domain_id 为系统内的域名ID。\n线路 line 使用 domain_query(action=quickinfo) 返回的线路 id，留空为默认线路。修改/删除前请先用 record_query 获取 RecordId。",
        'destructive' => true,
        'properties' => [
            'domain_id' => T::int('域名ID'),
            'recordid' => T::str('解析记录ID（update/delete/set_status/set_remark 必填）'),
            'name' => T::str('主机记录，如 www、@、*（add/update/edit_by_name 必填）'),
            'type' => T::str('记录类型，如 A、AAAA、CNAME、MX、TXT、NS、SRV、CAA'),
            'value' => T::str('记录值'),
            'line' => T::str('线路ID'),
            'ttl' => T::int('TTL，默认600'),
            'mx' => T::int('MX优先级，默认1'),
            'weight' => T::int('add/update：权重值'),
            'weights' => T::obj('weight_update：各记录权重，键为记录ID，值为权重，如 {"123": 10, "456": 20}'),
            'remark' => T::str('备注'),
            'status' => T::str('set_status：1=启用，0=暂停；weight_status/weight_update：1=开启权重，0=关闭', ['0', '1']),
            'recordinfo' => T::obj('update/delete/set_status：可选，原记录对象，用于生成更详细的操作日志'),
            'records' => T::arr('batch/batch_edit：要操作的记录对象数组', $recordObject),
            'batch_action' => T::str('batch：批量操作类型，open=启用，pause=暂停，delete=删除，remark=修改备注，group=修改分组', ['open', 'pause', 'delete', 'remark', 'group']),
            'groupid' => T::str('batch(group)：分组ID'),
            'edit_type' => T::str('batch_edit：value=批量修改记录值（需 type、value），line=批量修改线路（需 line）', ['value', 'line']),
            'record' => T::str("batch_add：批量记录文本，每行一条，格式为“主机记录 记录值”，如\nwww 1.2.3.4\napi 5.6.7.8"),
            'proxy' => T::bool('batch_add：Cloudflare 是否开启代理（未指定 line 时生效）'),
            'subdomain' => T::str('weight_status/weight_update：子域名主机记录'),
        ],
        'required' => ['domain_id'],
        'actions' => [
            'add' => [
                'desc' => '添加解析记录（name、type、value 必填）',
                'level' => 1,
                'call' => [Domain::class, 'record_add'],
                'route' => ['id' => '@domain_id'],
            ],
            'update' => [
                'desc' => '修改解析记录（recordid、name、type、value 必填，line/ttl 等需传入完整值）',
                'level' => 1,
                'call' => [Domain::class, 'record_update'],
                'route' => ['id' => '@domain_id'],
                'json' => ['recordinfo'],
            ],
            'delete' => [
                'desc' => '删除解析记录（recordid 必填）',
                'level' => 1,
                'call' => [Domain::class, 'record_delete'],
                'route' => ['id' => '@domain_id'],
                'json' => ['recordinfo'],
            ],
            'set_status' => [
                'desc' => '启用或暂停解析记录（recordid、status 必填）',
                'level' => 1,
                'call' => [Domain::class, 'record_status'],
                'route' => ['id' => '@domain_id'],
                'json' => ['recordinfo'],
            ],
            'set_remark' => [
                'desc' => '修改解析记录备注（recordid 必填）',
                'level' => 1,
                'call' => [Domain::class, 'record_remark'],
                'route' => ['id' => '@domain_id'],
            ],
            'batch' => [
                'desc' => '批量启用/暂停/删除/修改备注/修改分组（records、batch_action 必填）',
                'level' => 1,
                'call' => [Domain::class, 'record_batch'],
                'route' => ['id' => '@domain_id'],
                'json' => ['recordinfo'],
                'before' => function ($args) use ($withRecords) {
                    T::requireArgs($args, ['batch_action']);
                    $args = $withRecords($args);
                    $args['action'] = $args['batch_action'];
                    return $args;
                },
            ],
            'batch_edit' => [
                'desc' => '批量修改记录值或线路（records、edit_type 必填）',
                'level' => 1,
                'call' => [Domain::class, 'record_batch_edit'],
                'route' => ['id' => '@domain_id'],
                'json' => ['recordinfo'],
                'before' => function ($args) use ($withRecords) {
                    T::requireArgs($args, ['edit_type']);
                    $args = $withRecords($args);
                    $args['action'] = $args['edit_type'];
                    return $args;
                },
            ],
            'batch_add' => [
                'desc' => '批量添加解析记录（record 必填；type 留空时根据记录值自动识别 A/AAAA/CNAME）',
                'level' => 1,
                'call' => [Domain::class, 'record_batch_add'],
                'route' => ['id' => '@domain_id'],
            ],
            'edit_by_name' => [
                'desc' => '按主机记录修改：将 name 下默认线路的同类型记录值统一改为 value（name、type、value 必填）',
                'level' => 1,
                'call' => [Domain::class, 'record_batch_edit2'],
                'before' => function ($args) {
                    $args['id'] = $args['domain_id'];
                    return $args;
                },
            ],
            'weight_status' => [
                'desc' => '开启或关闭子域名的权重配置（subdomain、status、type、line 必填，仅阿里云）',
                'level' => 1,
                'call' => [Domain::class, 'weight'],
                'route' => ['id' => '@domain_id', 'act' => 'status'],
            ],
            'weight_update' => [
                'desc' => '设置子域名下各记录的权重（subdomain、status、type、line 必填，status=1 时需 weights，仅阿里云）',
                'level' => 1,
                'call' => [Domain::class, 'weight'],
                'route' => ['id' => '@domain_id', 'act' => 'update'],
                'before' => function ($args) {
                    if (isset($args['weights'])) {
                        $args['weight'] = $args['weights'];
                        unset($args['weights']);
                    }
                    return $args;
                },
            ],
        ],
    ],
];
