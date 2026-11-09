<?php

use app\controller\Cloudflare;
use app\lib\mcp\ToolRegistry as T;

$joinList = function (string $key, string $glue) {
    return function ($args) use ($key, $glue) {
        if (isset($args[$key]) && is_array($args[$key])) {
            $args[$key] = implode($glue, $args[$key]);
        }
        return $args;
    };
};

$tlsEnum = ['1.0', '1.1', '1.2', '1.3'];

return [
    [
        'name' => 'cf_hostname_query',
        'title' => '查询Cloudflare自定义主机名',
        'description' => '查询 Cloudflare 域名（SaaS）的自定义主机名、回退源、DCV委派信息。domain_id 为系统内 Cloudflare 类型域名的ID。',
        'readOnly' => true,
        'properties' => [
            'domain_id' => T::int('Cloudflare 域名ID'),
            'hostname' => T::str('txt_targets：需要添加TXT验证记录的主机名'),
        ],
        'required' => ['domain_id'],
        'actions' => [
            'list' => [
                'desc' => '自定义主机名列表（含证书验证状态与验证记录）',
                'call' => [Cloudflare::class, 'hostnames_data'],
                'route' => ['id' => '@domain_id'],
            ],
            'txt_targets' => [
                'desc' => '查找可用于添加TXT验证记录的本地域名',
                'call' => [Cloudflare::class, 'hostnames_txt_targets'],
                'route' => ['id' => '@domain_id'],
            ],
            'fallback_get' => [
                'desc' => '获取回退源（Fallback Origin）',
                'call' => [Cloudflare::class, 'fallback_get'],
                'route' => ['id' => '@domain_id'],
            ],
            'dcv_uuid' => [
                'desc' => '获取DCV委派UUID',
                'call' => [Cloudflare::class, 'dcv_delegation_uuid'],
                'route' => ['id' => '@domain_id'],
            ],
        ],
    ],
    [
        'name' => 'cf_hostname_manage',
        'title' => '管理Cloudflare自定义主机名',
        'description' => '创建、修改、删除 Cloudflare 自定义主机名（SaaS），以及设置回退源。domain_id 为系统内 Cloudflare 类型域名的ID。',
        'destructive' => true,
        'properties' => [
            'domain_id' => T::int('Cloudflare 域名ID'),
            'hostname' => T::str('add：自定义主机名；delete：主机名（用于日志）'),
            'hostnames' => T::arr('batch_add：主机名列表'),
            'hostname_id' => T::str('update/refresh/delete：自定义主机名ID'),
            'hostname_ids' => T::arr('batch_update/batch_delete：自定义主机名ID列表'),
            'custom_origin_server' => T::str('自定义源站，留空使用回退源'),
            'ssl_method' => T::str('证书验证方式，默认txt', ['txt', 'http']),
            'min_tls_version' => T::str('最低TLS版本，默认1.0', $tlsEnum),
            'origin' => T::str('fallback_set：回退源主机名'),
        ],
        'required' => ['domain_id'],
        'actions' => [
            'add' => [
                'desc' => '创建自定义主机名（hostname 必填）',
                'call' => [Cloudflare::class, 'hostnames_add'],
                'route' => ['id' => '@domain_id'],
            ],
            'batch_add' => [
                'desc' => '批量创建自定义主机名（hostnames 必填）',
                'call' => [Cloudflare::class, 'hostnames_batch_add'],
                'route' => ['id' => '@domain_id'],
                'before' => $joinList('hostnames', "\n"),
            ],
            'update' => [
                'desc' => '修改自定义主机名的源站与证书设置（hostname_id 必填）',
                'call' => [Cloudflare::class, 'hostnames_update'],
                'route' => ['id' => '@domain_id'],
            ],
            'batch_update' => [
                'desc' => '批量修改源站与证书设置（hostname_ids 必填，ssl_method/min_tls_version 留空则不修改）',
                'call' => [Cloudflare::class, 'hostnames_batch_update'],
                'route' => ['id' => '@domain_id'],
                'before' => $joinList('hostname_ids', ','),
            ],
            'refresh' => [
                'desc' => '重新发起证书验证（hostname_id 必填）',
                'call' => [Cloudflare::class, 'hostnames_refresh'],
                'route' => ['id' => '@domain_id'],
            ],
            'delete' => [
                'desc' => '删除自定义主机名（hostname_id 必填）',
                'call' => [Cloudflare::class, 'hostnames_delete'],
                'route' => ['id' => '@domain_id'],
            ],
            'batch_delete' => [
                'desc' => '批量删除自定义主机名（hostname_ids 必填）',
                'call' => [Cloudflare::class, 'hostnames_batch_delete'],
                'route' => ['id' => '@domain_id'],
            ],
            'fallback_set' => [
                'desc' => '设置回退源（origin 必填）',
                'call' => [Cloudflare::class, 'fallback_set'],
                'route' => ['id' => '@domain_id'],
            ],
            'fallback_delete' => [
                'desc' => '清空回退源',
                'call' => [Cloudflare::class, 'fallback_delete'],
                'route' => ['id' => '@domain_id'],
            ],
        ],
    ],
    [
        'name' => 'cf_tunnel_query',
        'title' => '查询Cloudflare Tunnel',
        'description' => '查询 Cloudflare Tunnel（隧道）及其公网主机名、私有网络CIDR路由、主机名路由。account_id 为系统内 Cloudflare 类型域名账户的ID（需使用API令牌认证）。',
        'readOnly' => true,
        'properties' => [
            'account_id' => T::int('Cloudflare 域名账户ID（系统内ID）'),
            'tunnel_id' => T::str('Tunnel ID（list 以外的操作必填）'),
        ],
        'required' => ['account_id'],
        'actions' => [
            'list' => [
                'desc' => 'Tunnel 列表',
                'call' => [Cloudflare::class, 'tunnels_data'],
                'route' => ['id' => '@account_id'],
            ],
            'token' => [
                'desc' => '获取 Tunnel 的运行令牌（用于 cloudflared 连接）',
                'call' => [Cloudflare::class, 'tunnels_token'],
                'route' => ['id' => '@account_id'],
            ],
            'public_hostnames' => [
                'desc' => 'Tunnel 的公网主机名（Public Hostname）列表',
                'call' => [Cloudflare::class, 'tunnels_public_hostnames_data'],
                'route' => ['id' => '@account_id'],
            ],
            'cidr_routes' => [
                'desc' => 'Tunnel 的私有网络CIDR路由列表',
                'call' => [Cloudflare::class, 'tunnels_cidr_data'],
                'route' => ['id' => '@account_id'],
            ],
            'hostname_routes' => [
                'desc' => 'Tunnel 的主机名路由列表',
                'call' => [Cloudflare::class, 'tunnels_hostname_routes_data'],
                'route' => ['id' => '@account_id'],
            ],
        ],
    ],
    [
        'name' => 'cf_tunnel_manage',
        'title' => '管理Cloudflare Tunnel',
        'description' => '创建、删除 Cloudflare Tunnel，配置公网主机名、CIDR路由、主机名路由。account_id 为系统内 Cloudflare 类型域名账户的ID。',
        'destructive' => true,
        'properties' => [
            'account_id' => T::int('Cloudflare 域名账户ID（系统内ID）'),
            'tunnel_id' => T::str('Tunnel ID（add 以外的操作必填）'),
            'name' => T::str('add：Tunnel 名称'),
            'hostname' => T::str('public_hostname_save/public_hostname_delete/hostname_route_add：主机名'),
            'service' => T::str('public_hostname_save：服务地址，如 http://localhost:8080'),
            'path' => T::str('public_hostname_save/public_hostname_delete：路径（可选）'),
            'network' => T::str('cidr_add：CIDR网段，如 10.0.0.0/24'),
            'comment' => T::str('cidr_add/hostname_route_add：备注'),
            'route_id' => T::str('cidr_delete/hostname_route_delete：路由ID'),
        ],
        'required' => ['account_id'],
        'actions' => [
            'add' => [
                'desc' => '创建 Tunnel（name 必填）',
                'call' => [Cloudflare::class, 'tunnels_add'],
                'route' => ['id' => '@account_id'],
            ],
            'delete' => [
                'desc' => '删除 Tunnel',
                'call' => [Cloudflare::class, 'tunnels_delete'],
                'route' => ['id' => '@account_id'],
            ],
            'public_hostname_save' => [
                'desc' => '添加或更新公网主机名，并自动创建CNAME解析（hostname、service 必填）',
                'call' => [Cloudflare::class, 'tunnels_public_hostnames_save'],
                'route' => ['id' => '@account_id'],
            ],
            'public_hostname_delete' => [
                'desc' => '删除公网主机名（hostname 必填）',
                'call' => [Cloudflare::class, 'tunnels_public_hostnames_delete'],
                'route' => ['id' => '@account_id'],
            ],
            'cidr_add' => [
                'desc' => '添加CIDR路由（network 必填）',
                'call' => [Cloudflare::class, 'tunnels_cidr_add'],
                'route' => ['id' => '@account_id'],
            ],
            'cidr_delete' => [
                'desc' => '删除CIDR路由（route_id 必填）',
                'call' => [Cloudflare::class, 'tunnels_cidr_delete'],
                'route' => ['id' => '@account_id'],
            ],
            'hostname_route_add' => [
                'desc' => '添加主机名路由（hostname 必填）',
                'call' => [Cloudflare::class, 'tunnels_hostname_routes_add'],
                'route' => ['id' => '@account_id'],
            ],
            'hostname_route_delete' => [
                'desc' => '删除主机名路由（route_id 必填）',
                'call' => [Cloudflare::class, 'tunnels_hostname_routes_delete'],
                'route' => ['id' => '@account_id'],
            ],
        ],
    ],
];
