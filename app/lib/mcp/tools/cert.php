<?php

use app\controller\Cert;
use app\lib\mcp\McpException;
use app\lib\mcp\ToolRegistry as T;
use think\facade\Db;

return [
    [
        'name' => 'cert_account_query',
        'title' => '查询SSL证书/部署账户',
        'description' => '查询SSL证书申请账户（deploy=0）或证书自动部署账户（deploy=1）列表。',
        'readOnly' => true,
        'properties' => array_merge([
            'deploy' => T::int('0=SSL证书账户（默认），1=自动部署账户'),
            'kw' => T::str('按名称、备注或ID搜索'),
        ], T::paging()),
        'actions' => [
            'list' => [
                'desc' => '账户列表',
                'call' => [Cert::class, 'account_data'],
                'defaults' => array_merge(T::pagingDefaults(), ['deploy' => 0]),
            ],
        ],
    ],
    [
        'name' => 'cert_account_manage',
        'title' => '管理SSL证书/部署账户',
        'description' => '添加、修改、删除SSL证书申请账户（deploy=0）或证书自动部署账户（deploy=1）。添加前用 list_provider_types(category=cert 或 deploy, type=xxx) 查看 config 字段。添加/修改时会自动校验账户可用性。',
        'destructive' => true,
        'properties' => [
            'deploy' => T::int('0=SSL证书账户（默认），1=自动部署账户'),
            'id' => T::int('账户ID（edit/del 必填）'),
            'type' => T::str('账户类型标识，如 letsencrypt、zerossl、btpanel、ssh（add 必填）'),
            'config' => T::obj('账户配置对象，字段见 list_provider_types 的 inputs；edit 时只需传入要修改的字段'),
            'name' => T::str('账户名称，留空默认取 config 的第一个字段值'),
            'remark' => T::str('备注'),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加账户（type、config 必填）',
                'call' => [Cert::class, 'account_op'],
                'route' => ['action' => 'add'],
                'defaults' => ['deploy' => 0],
                'json' => ['config'],
                'before' => function ($args) {
                    T::requireArgs($args, ['type', 'config']);
                    return T::accountNameFromConfig($args);
                },
            ],
            'edit' => [
                'desc' => '修改账户（id 必填，未传字段保持不变）',
                'call' => [Cert::class, 'account_op'],
                'route' => ['action' => 'edit'],
                'json' => ['config'],
                'before' => T::prefill('cert_account', ['type', 'name', 'config', 'remark', 'deploy']),
            ],
            'del' => [
                'desc' => '删除账户（id 必填）',
                'call' => [Cert::class, 'account_op'],
                'route' => ['action' => 'del'],
                'before' => T::prefill('cert_account', ['deploy']),
            ],
        ],
    ],
    [
        'name' => 'cert_order_query',
        'title' => '查询SSL证书订单',
        'description' => '查询SSL证书订单列表、证书内容（证书链与私钥）、申请过程日志。',
        'readOnly' => true,
        'properties' => array_merge([
            'id' => T::int('证书订单ID（get 必填；list 可按ID筛选）'),
            'domain' => T::str('list：按绑定域名搜索'),
            'aid' => T::int('list：按证书账户ID筛选'),
            'type' => T::str('list：按证书账户类型筛选，如 letsencrypt'),
            'status' => T::str('list：状态筛选，0=待提交，1=待验证，2=验证中，3=已签发，4=已吊销，5=失败，6=7天内到期，7=已过期'),
            'include_pfx' => T::bool('get：是否同时返回 base64 编码的 PFX 证书（密码 123456），默认否'),
            'processid' => T::str('show_log：处理过程ID（取自订单的 processid 字段）'),
        ], T::paging()),
        'actions' => [
            'list' => [
                'desc' => '证书订单列表',
                'call' => [Cert::class, 'order_data'],
                'defaults' => T::pagingDefaults(),
            ],
            'get' => [
                'desc' => '获取证书内容（crt 证书链、key 私钥、到期时间、域名）',
                'call' => [Cert::class, 'order_info'],
                'after' => function ($data, $args) {
                    if (empty($args['include_pfx']) || $args['include_pfx'] === '0') {
                        unset($data['data']['pfx']);
                    }
                    return $data;
                },
            ],
            'show_log' => [
                'desc' => '查看证书申请过程日志',
                'call' => [Cert::class, 'order_op'],
                'route' => ['action' => 'show_log'],
            ],
        ],
    ],
    [
        'name' => 'cert_order_manage',
        'title' => '管理SSL证书订单',
        'description' => "添加、修改、删除SSL证书订单，执行证书申请、重置、吊销等操作。\n添加自动申请订单：aid=证书账户ID，domains=域名列表，keytype=RSA 或 ECC，keysize：RSA 为 2048/3072，ECC 为 256/384。添加后调用 process 开始申请。\n上传已有证书（手动续期）：aid=-1，fullchain=证书链PEM，privatekey=私钥PEM。",
        'destructive' => true,
        'properties' => [
            'id' => T::int('证书订单ID'),
            'ids' => T::ids('batch：订单ID列表'),
            'aid' => T::int('证书账户ID，-1 表示上传已有证书'),
            'domains' => T::arr('绑定域名列表，支持通配符，如 ["example.com", "*.example.com"]'),
            'keytype' => T::str('密钥类型', ['RSA', 'ECC']),
            'keysize' => T::str('密钥长度', ['2048', '3072', '256', '384']),
            'fullchain' => T::str('aid=-1：证书链PEM'),
            'privatekey' => T::str('aid=-1：私钥PEM'),
            'isauto' => T::bool('set_auto：是否自动续期'),
            'reset' => T::bool('process：是否先重置订单再重新申请'),
            'act' => T::str('batch：操作类型', ['delete', 'reset', 'open', 'close']),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加证书订单（aid 必填）',
                'call' => [Cert::class, 'order_op'],
                'route' => ['action' => 'add'],
                'defaults' => ['keytype' => 'RSA', 'keysize' => '2048'],
            ],
            'edit' => [
                'desc' => '修改证书订单（id 必填，未传字段保持不变）',
                'call' => [Cert::class, 'order_op'],
                'route' => ['action' => 'edit'],
                'before' => function ($args) {
                    T::requireArgs($args, ['id']);
                    $row = Db::name('cert_order')->where('id', intval($args['id']))->find();
                    if (!$row) throw new McpException('证书订单不存在');
                    if (!isset($args['aid'])) $args['aid'] = $row['aid'] == 0 ? -1 : $row['aid'];
                    if ($args['aid'] == -1) {
                        if (!isset($args['fullchain'])) $args['fullchain'] = $row['fullchain'];
                        if (!isset($args['privatekey'])) $args['privatekey'] = $row['privatekey'];
                    } else {
                        if (!isset($args['keytype'])) $args['keytype'] = $row['keytype'];
                        if (!isset($args['keysize'])) $args['keysize'] = $row['keysize'];
                        if (!isset($args['domains'])) {
                            $args['domains'] = Db::name('cert_domain')->where('oid', $row['id'])->order('sort', 'ASC')->column('domain');
                        }
                    }
                    return $args;
                },
            ],
            'del' => [
                'desc' => '删除证书订单（id 必填，关联了部署任务时无法删除）',
                'call' => [Cert::class, 'order_op'],
                'route' => ['action' => 'del'],
            ],
            'process' => [
                'desc' => '执行证书申请/验证流程（id 必填，可能耗时较长）',
                'call' => [Cert::class, 'order_process'],
            ],
            'set_auto' => [
                'desc' => '设置是否自动续期（id、isauto 必填）',
                'call' => [Cert::class, 'order_op'],
                'route' => ['action' => 'setauto'],
            ],
            'reset' => [
                'desc' => '重置订单状态以便重新申请（id 必填）',
                'call' => [Cert::class, 'order_op'],
                'route' => ['action' => 'reset'],
            ],
            'revoke' => [
                'desc' => '吊销证书（id 必填，不可恢复）',
                'call' => [Cert::class, 'order_op'],
                'route' => ['action' => 'revoke'],
            ],
            'batch' => [
                'desc' => '批量操作（ids、act 必填）',
                'call' => [Cert::class, 'order_op'],
                'route' => ['action' => 'operation'],
            ],
        ],
    ],
    [
        'name' => 'cert_deploy_query',
        'title' => '查询证书部署任务',
        'description' => '查询SSL证书自动部署任务列表和部署过程日志。',
        'readOnly' => true,
        'properties' => array_merge([
            'oid' => T::int('list：按证书订单ID筛选'),
            'domain' => T::str('list：按证书绑定域名搜索'),
            'aid' => T::int('list：按部署账户ID筛选'),
            'type' => T::str('list：按部署账户类型筛选，如 btpanel'),
            'status' => T::str('list：状态筛选，0=待处理，1=成功，-1=失败'),
            'remark' => T::str('list：按备注精确筛选'),
            'processid' => T::str('show_log：处理过程ID（取自任务的 processid 字段）'),
        ], T::paging()),
        'actions' => [
            'list' => [
                'desc' => '部署任务列表',
                'call' => [Cert::class, 'deploy_data'],
                'defaults' => T::pagingDefaults(),
            ],
            'show_log' => [
                'desc' => '查看部署过程日志',
                'call' => [Cert::class, 'deploy_op'],
                'route' => ['action' => 'show_log'],
            ],
        ],
    ],
    [
        'name' => 'cert_deploy_manage',
        'title' => '管理证书部署任务',
        'description' => '添加、修改、删除、启停、执行SSL证书自动部署任务。config 字段为部署目标的任务参数，见 list_provider_types(category=deploy, type=部署账户类型) 返回的 taskinputs。',
        'destructive' => true,
        'properties' => [
            'id' => T::int('任务ID'),
            'ids' => T::ids('batch：任务ID列表'),
            'aid' => T::int('自动部署账户ID'),
            'oid' => T::int('证书订单ID'),
            'config' => T::obj('部署任务配置对象（taskinputs 字段）；edit 时只需传入要修改的字段'),
            'remark' => T::str('备注'),
            'active' => T::bool('set_active：是否启用'),
            'reset' => T::bool('process：是否先重置任务再执行'),
            'act' => T::str('batch：操作类型，cert=批量更换关联证书（需 certid）', ['delete', 'reset', 'open', 'close', 'cert']),
            'certid' => T::int('batch(cert)：新的证书订单ID'),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加部署任务（aid、oid、config 必填）',
                'call' => [Cert::class, 'deploy_op'],
                'route' => ['action' => 'add'],
                'json' => ['config'],
            ],
            'edit' => [
                'desc' => '修改部署任务（id 必填，未传字段保持不变）',
                'call' => [Cert::class, 'deploy_op'],
                'route' => ['action' => 'edit'],
                'json' => ['config'],
                'before' => T::prefill('cert_deploy', ['aid', 'oid', 'config', 'remark']),
            ],
            'del' => [
                'desc' => '删除部署任务（id 必填）',
                'call' => [Cert::class, 'deploy_op'],
                'route' => ['action' => 'del'],
            ],
            'set_active' => [
                'desc' => '启用或停用部署任务（id、active 必填）',
                'call' => [Cert::class, 'deploy_op'],
                'route' => ['action' => 'setactive'],
            ],
            'reset' => [
                'desc' => '重置部署任务状态（id 必填）',
                'call' => [Cert::class, 'deploy_op'],
                'route' => ['action' => 'reset'],
            ],
            'process' => [
                'desc' => '立即执行部署（id 必填，可能耗时较长）',
                'call' => [Cert::class, 'deploy_process'],
            ],
            'batch' => [
                'desc' => '批量操作（ids、act 必填）',
                'call' => [Cert::class, 'deploy_op'],
                'route' => ['action' => 'operation'],
            ],
        ],
    ],
    [
        'name' => 'cert_cname_query',
        'title' => '查询CNAME代理',
        'description' => '查询ACME证书验证用的CNAME代理列表（将 _acme-challenge 委派到系统内域名）。',
        'readOnly' => true,
        'properties' => array_merge([
            'kw' => T::str('按域名搜索'),
        ], T::paging()),
        'actions' => [
            'list' => [
                'desc' => 'CNAME代理列表（host 为需添加CNAME的主机记录，record 为CNAME目标）',
                'call' => [Cert::class, 'cname_data'],
                'defaults' => T::pagingDefaults(),
            ],
        ],
    ],
    [
        'name' => 'cert_cname_manage',
        'title' => '管理CNAME代理',
        'description' => '添加、修改、删除、检测ACME证书验证用的CNAME代理。',
        'destructive' => true,
        'properties' => [
            'id' => T::int('CNAME代理ID'),
            'domain' => T::str('add：被代理的域名'),
            'rr' => T::str('代理记录的主机记录'),
            'did' => T::int('代理记录所在的域名ID（系统内）'),
        ],
        'actions' => [
            'add' => [
                'desc' => '添加CNAME代理（domain、rr、did 必填）',
                'call' => [Cert::class, 'cname_op'],
                'route' => ['action' => 'add'],
            ],
            'edit' => [
                'desc' => '修改CNAME代理（id 必填）',
                'call' => [Cert::class, 'cname_op'],
                'route' => ['action' => 'edit'],
                'before' => T::prefill('cert_cname', ['rr', 'did']),
            ],
            'del' => [
                'desc' => '删除CNAME代理（id 必填）',
                'call' => [Cert::class, 'cname_op'],
                'route' => ['action' => 'del'],
            ],
            'check' => [
                'desc' => '检测CNAME记录是否已生效（id 必填）',
                'call' => [Cert::class, 'cname_op'],
                'route' => ['action' => 'check'],
            ],
        ],
    ],
];
