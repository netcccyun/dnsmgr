<?php

use app\controller\Domain;
use app\controller\Index;
use app\controller\User;
use app\lib\CertHelper;
use app\lib\DeployHelper;
use app\lib\DnsHelper;
use app\lib\mcp\McpException;
use app\lib\mcp\ToolRegistry as T;
use think\facade\Db;

return [
    [
        'name' => 'get_overview',
        'title' => '系统概况',
        'description' => '获取系统首页统计数据：域名数量、容灾监控任务、优选IP任务、SSL证书订单与部署任务的数量和状态。',
        'readOnly' => true,
        'actions' => [
            'stat' => [
                'desc' => '获取统计数据',
                'level' => 1,
                'call' => [Index::class, 'index'],
                'before' => function ($args) {
                    return ['do' => 'stat'];
                },
            ],
        ],
    ],
    [
        'name' => 'list_provider_types',
        'title' => '服务商类型列表',
        'description' => "查询系统支持的服务商类型及其配置字段定义。添加/编辑账户或部署任务前调用，了解 config 需要填写哪些字段。\n- category=dns：DNS服务商（用于 dns_account_manage）\n- category=cert：SSL证书申请服务商（用于 cert_account_manage 的 deploy=0）\n- category=deploy：证书自动部署目标（用于 cert_account_manage 的 deploy=1，taskinputs 为 cert_deploy_manage 的 config 字段）\n不传 type 时返回类型摘要列表，传入 type 时返回该类型的完整字段定义。",
        'readOnly' => true,
        'properties' => [
            'category' => T::str('服务商类别', ['dns', 'cert', 'deploy']),
            'type' => T::str('服务商类型标识，如 aliyun、dnspod、cloudflare、letsencrypt、btpanel'),
        ],
        'required' => ['category'],
        'actions' => [
            'query' => [
                'desc' => '查询服务商类型',
                'handler' => function ($args) {
                    $category = $args['category'] ?? '';
                    if ($category == 'dns') {
                        $list = DnsHelper::$dns_config;
                    } elseif ($category == 'cert') {
                        $list = CertHelper::$cert_config;
                    } elseif ($category == 'deploy') {
                        $list = DeployHelper::$deploy_config;
                    } else {
                        throw new McpException('category 参数无效');
                    }
                    $type = $args['type'] ?? '';
                    if ($type !== '') {
                        if (!isset($list[$type])) throw new McpException('服务商类型不存在：' . $type);
                        $info = $list[$type];
                        unset($info['icon']);
                        return ['code' => 0, 'data' => array_merge(['type' => $type], $info)];
                    }
                    $result = [];
                    foreach ($list as $key => $item) {
                        $row = ['type' => $key, 'name' => $item['name']];
                        if (isset($item['class'])) $row['class'] = $item['class'];
                        if (!empty($item['desc'])) $row['desc'] = $item['desc'];
                        $row['fields'] = array_keys($item['config'] ?? $item['inputs'] ?? []);
                        $result[] = $row;
                    }
                    return ['code' => 0, 'data' => $result];
                },
            ],
        ],
    ],
    [
        'name' => 'dns_account_query',
        'title' => '查询域名账户',
        'description' => '查询已添加的DNS服务商账户（域名账户）列表，返回结果包含账户ID、类型、名称、配置等。',
        'readOnly' => true,
        'properties' => array_merge([
            'kw' => T::str('按名称或备注搜索'),
        ], T::paging()),
        'actions' => [
            'list' => [
                'desc' => '账户列表',
                'call' => [Domain::class, 'account_data'],
                'defaults' => T::pagingDefaults(),
            ],
        ],
    ],
    [
        'name' => 'dns_account_manage',
        'title' => '管理域名账户',
        'description' => '添加、修改、删除DNS服务商账户。添加前请用 list_provider_types(category=dns, type=xxx) 查看 config 字段。添加/修改时会自动校验账户可用性。',
        'destructive' => true,
        'properties' => [
            'id' => T::int('账户ID（edit/del 必填）'),
            'type' => T::str('DNS服务商类型标识，如 aliyun、dnspod、cloudflare（add 必填）'),
            'config' => T::obj('账户配置对象，字段见 list_provider_types；edit 时只需传入要修改的字段'),
            'name' => T::str('账户名称，留空默认取 config 的第一个字段值'),
            'remark' => T::str('备注'),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加账户（type、config 必填）',
                'call' => [Domain::class, 'account_op'],
                'route' => ['action' => 'add'],
                'json' => ['config'],
                'before' => function ($args) {
                    T::requireArgs($args, ['type', 'config']);
                    return T::accountNameFromConfig($args);
                },
            ],
            'edit' => [
                'desc' => '修改账户（id 必填，未传字段保持不变）',
                'call' => [Domain::class, 'account_op'],
                'route' => ['action' => 'edit'],
                'json' => ['config'],
                'before' => T::prefill('account', ['type', 'name', 'config', 'remark']),
            ],
            'del' => [
                'desc' => '删除账户（id 必填，账户下存在域名时无法删除）',
                'call' => [Domain::class, 'account_op'],
                'route' => ['action' => 'del'],
            ],
        ],
    ],
    [
        'name' => 'domain_query',
        'title' => '查询域名',
        'description' => '查询系统中已添加的域名及其信息。管理解析记录前，通常先用 list 获取域名ID。',
        'readOnly' => true,
        'properties' => array_merge([
            'id' => T::int('域名ID（get/info/quickinfo/aliases 必填）'),
            'kw' => T::str('list：按域名或备注搜索；remote_list：搜索关键词'),
            'aid' => T::int('域名账户ID（list 可选筛选；remote_list 必填）'),
            'type' => T::str('list：按DNS服务商类型筛选，如 aliyun'),
            'cid' => T::int('list：按分类ID筛选'),
            'status' => T::str('list：到期状态筛选，1=30天内到期，2=已到期', ['1', '2']),
            'loginurl' => T::bool('info：是否生成该域名的单独登录链接'),
            'page' => T::int('remote_list：页码，默认1'),
            'pagesize' => T::int('remote_list：每页数量，默认10'),
        ], T::paging()),
        'actions' => [
            'list' => [
                'desc' => '已添加的域名列表',
                'level' => 1,
                'call' => [Domain::class, 'domain_data'],
                'defaults' => T::pagingDefaults(),
            ],
            'get' => [
                'desc' => '获取域名基本信息',
                'level' => 1,
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'get'],
            ],
            'info' => [
                'desc' => '获取域名详细信息（含DNS服务商能力、解析线路列表、最小TTL）',
                'level' => 1,
                'call' => [Domain::class, 'domain_info'],
                'route' => ['id' => '@id'],
            ],
            'quickinfo' => [
                'desc' => '获取域名可用的解析线路、最小TTL、是否支持权重/备注/ALIAS',
                'level' => 1,
                'call' => [Domain::class, 'quickinfo'],
                'route' => ['id' => '@id'],
            ],
            'aliases' => [
                'desc' => '获取域名别名列表（部分DNS服务商支持）',
                'level' => 1,
                'handler' => function ($args) {
                    T::requireArgs($args, ['id']);
                    $drow = Db::name('domain')->where('id', intval($args['id']))->find();
                    if (!$drow) throw new McpException('域名不存在');
                    if (!checkPermission(0, $drow['name'])) throw new McpException('无权限');
                    $dns = DnsHelper::getModel($drow['aid'], $drow['name'], $drow['thirdid']);
                    if (!$dns) throw new McpException('DNS模块不存在');
                    $list = $dns->domainAliasList();
                    if ($list === false) throw new McpException('获取域名别名失败，' . $dns->getError());
                    return ['code' => 0, 'data' => $list];
                },
            ],
            'remote_list' => [
                'desc' => '从DNS服务商账户中拉取可添加的域名列表（disabled=true 表示已添加）',
                'call' => [Domain::class, 'domain_list'],
                'before' => function ($args) {
                    T::requireArgs($args, ['aid']);
                    return $args;
                },
            ],
            'categories' => [
                'desc' => '域名分类列表',
                'call' => [Domain::class, 'category_list'],
            ],
        ],
    ],
    [
        'name' => 'domain_manage',
        'title' => '管理域名',
        'description' => '添加、修改、删除系统中的域名，以及批量操作、到期时间刷新、设置分类、管理域名别名。',
        'destructive' => true,
        'properties' => [
            'id' => T::int('域名ID'),
            'ids' => T::ids('域名ID列表（批量操作）'),
            'aid' => T::int('域名账户ID（add/batch_add 必填）'),
            'name' => T::str('add：域名'),
            'thirdid' => T::str('add：域名在DNS服务商处的ID（method=0 时必填，可从 domain_query remote_list 获取）'),
            'method' => T::int('add：0=添加服务商中已存在的域名，1=在服务商中新建域名'),
            'recordcount' => T::int('add：记录数量'),
            'domains' => T::arr('batch_add：域名列表，每项为 {name, id, recordcount}，取自 domain_query remote_list 的 Domain/DomainId/RecordCount', [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string'], 'id' => ['type' => 'string'], 'recordcount' => ['type' => 'integer']],
            ]),
            'is_hide' => T::bool('edit：是否对普通用户隐藏'),
            'is_sso' => T::bool('edit：是否允许单独登录'),
            'is_notice' => T::bool('edit/batch_set_notice：是否开启到期提醒'),
            'cid' => T::int('edit/set_category：分类ID，0为未分类'),
            'expiretime' => T::str('edit：到期时间，格式 Y-m-d H:i:s'),
            'remark' => T::str('edit/batch_remark：备注'),
            'alias' => T::str('alias_add：别名域名'),
            'alias_id' => T::int('alias_delete：别名ID'),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加域名（aid、name 必填，method=0 时还需 thirdid）',
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'add'],
                'defaults' => ['method' => 0],
            ],
            'batch_add' => [
                'desc' => '批量添加域名（aid、domains 必填）',
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'batchadd'],
            ],
            'edit' => [
                'desc' => '修改域名设置（id 必填，未传字段保持不变）',
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'edit'],
                'before' => T::prefill('domain', ['is_hide', 'is_sso', 'is_notice', 'cid', 'expiretime', 'remark']),
            ],
            'del' => [
                'desc' => '从系统中删除域名（仅删除本地记录，不影响DNS服务商；id 必填）',
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'del'],
            ],
            'batch_del' => [
                'desc' => '批量删除域名（ids 必填）',
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'batchdel'],
            ],
            'batch_remark' => [
                'desc' => '批量修改备注（ids 必填）',
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'batchedit'],
            ],
            'batch_set_notice' => [
                'desc' => '批量设置到期提醒（ids、is_notice 必填）',
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'batchsetnotice'],
            ],
            'refresh_expire' => [
                'desc' => '批量提交刷新域名注册/到期时间（ids 必填，后台异步执行）',
                'call' => [Domain::class, 'domain_op'],
                'route' => ['act' => 'updateexpire'],
            ],
            'update_date' => [
                'desc' => '立即查询并更新单个域名的注册/到期时间（id 必填）',
                'level' => 1,
                'call' => [Domain::class, 'update_date'],
                'route' => ['id' => '@id'],
            ],
            'set_category' => [
                'desc' => '批量设置域名分类（ids、cid 必填）',
                'call' => [Domain::class, 'domain_set_category'],
            ],
            'alias_add' => [
                'desc' => '添加域名别名（id、alias 必填）',
                'level' => 1,
                'call' => [Domain::class, 'alias'],
                'route' => ['id' => '@id', 'act' => 'add'],
            ],
            'alias_delete' => [
                'desc' => '删除域名别名（id、alias_id 必填）',
                'level' => 1,
                'call' => [Domain::class, 'alias'],
                'route' => ['id' => '@id', 'act' => 'delete'],
            ],
        ],
    ],
    [
        'name' => 'domain_category_manage',
        'title' => '管理域名分类',
        'description' => '添加、修改、删除域名分类。查询分类列表请用 domain_query(action=categories)。',
        'destructive' => true,
        'properties' => [
            'id' => T::int('分类ID（edit/del 必填）'),
            'name' => T::str('分类名称'),
            'remark' => T::str('备注'),
            'sort' => T::int('排序，数字越小越靠前'),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加分类（name 必填）',
                'call' => [Domain::class, 'category_op'],
                'route' => ['action' => 'add'],
            ],
            'edit' => [
                'desc' => '修改分类（id 必填）',
                'call' => [Domain::class, 'category_op'],
                'route' => ['action' => 'edit'],
                'before' => T::prefill('domain_category', ['name', 'remark', 'sort']),
            ],
            'del' => [
                'desc' => '删除分类（id 必填，分类下有域名时无法删除）',
                'call' => [Domain::class, 'category_op'],
                'route' => ['action' => 'del'],
            ],
        ],
    ],
    [
        'name' => 'operation_log_query',
        'title' => '查询操作日志',
        'description' => '查询系统操作日志（解析记录增删改等）。普通用户只能看到自己的日志。',
        'readOnly' => true,
        'properties' => [
            'uid' => T::int('按用户ID筛选（仅管理员）'),
            'domain' => T::str('按域名筛选'),
            'kw' => T::str('按操作类型或内容搜索'),
            'offset' => T::int('分页偏移量，默认0'),
            'limit' => T::int('每页数量，默认20'),
        ],
        'actions' => [
            'list' => [
                'desc' => '日志列表',
                'level' => 1,
                'call' => [User::class, 'log_data'],
                'defaults' => T::pagingDefaults(),
            ],
        ],
    ],
];
