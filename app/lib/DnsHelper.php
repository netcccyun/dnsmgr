<?php

namespace app\lib;

use think\facade\Db;

class DnsHelper
{
    public static $dns_config = [
        'aliyun' => [
            'name' => '阿里云',
            'config' => [
                'ak' => 'AccessKeyId',
                'sk' => 'AccessKeySecret',
            ],
            'remark' => 1, //是否支持备注，1单独设置备注，2和记录一起设置
            'status' => true, //是否支持启用暂停
            'redirect' => true, //是否支持域名转发
            'log' => true, //是否支持查看日志
            'weight' => false, //是否支持权重
            'page' => false, //是否客户端分页
        ],
        'dnspod' => [
            'name' => '腾讯云',
            'config' => [
                'ak' => 'SecretId',
                'sk' => 'SecretKey',
            ],
            'remark' => 1,
            'status' => true,
            'redirect' => true,
            'log' => true,
            'weight' => true,
            'page' => false,
        ],
        'huawei' => [
            'name' => '华为云',
            'config' => [
                'ak' => 'AccessKeyId',
                'sk' => 'SecretAccessKey',
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => true,
            'page' => false,
        ],
        'baidu' => [
            'name' => '百度云',
            'config' => [
                'ak' => 'AccessKey',
                'sk' => 'SecretKey',
            ],
            'remark' => 2,
            'status' => false,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => true,
        ],
        'west' => [
            'name' => '西部数码',
            'config' => [
                'ak' => '用户名',
                'sk' => 'API密码',
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => false,
        ],
        'huoshan' => [
            'name' => '火山引擎',
            'config' => [
                'ak' => 'AccessKeyId',
                'sk' => 'SecretAccessKey',
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => true,
            'page' => false,
        ],
        'jdcloud' => [
            'name' => '京东云',
            'config' => [
                'ak' => 'AccessKeyId',
                'sk' => 'AccessKeySecret',
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => true,
            'log' => false,
            'weight' => true,
            'page' => false,
        ],
        'dnsla' => [
            'name' => 'DNSLA',
            'config' => [
                'ak' => 'APIID',
                'sk' => 'API密钥',
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => true,
            'log' => false,
            'weight' => true,
            'page' => false,
        ],
        'cloudflare' => [
            'name' => 'Cloudflare',
            'config' => [
                'ak' => '邮箱地址',
                'sk' => 'API密钥/令牌',
            ],
            'remark' => 2,
            'status' => false,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => false,
        ],
        'namesilo' => [
            'name' => 'NameSilo',
            'config' => [
                'ak' => '账户名',
                'sk' => 'API Key',
            ],
            'remark' => 0,
            'status' => false,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => true,
        ],
        'powerdns' => [
            'name' => 'PowerDNS',
            'config' => [
                'ak' => 'IP地址',
                'sk' => '端口',
                'ext' => 'API KEY',
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => true,
        ],
    ];

    public static $line_name = [
        'aliyun' => ['DEF' => 'default', 'CT' => 'telecom', 'CU' => 'unicom', 'CM' => 'mobile', 'AB' => 'oversea'],
        'dnspod' => ['DEF' => '0', 'CT' => '10=0', 'CU' => '10=1', 'CM' => '10=3', 'AB' => '3=0'],
        'huawei' => ['DEF' => 'default_view', 'CT' => 'Dianxin', 'CU' => 'Liantong', 'CM' => 'Yidong', 'AB' => 'Abroad'],
        'west' => ['DEF' => '', 'CT' => 'LTEL', 'CU' => 'LCNC', 'CM' => 'LMOB', 'AB' => 'LFOR'],
        'dnsla' => ['DEF' => '', 'CT' => '84613316902921216', 'CU' => '84613316923892736', 'CM' => '84613316953252864', 'AB' => ''],
        'huoshan' => ['DEF' => 'default', 'CT' => 'telecom', 'CU' => 'unicom', 'CM' => 'mobile', 'AB' => 'oversea'],
        'baidu' => ['DEF' => 'default', 'CT' => 'ct', 'CU' => 'cnc', 'CM' => 'cmnet', 'AB' => ''],
        'cloudflare' => ['DEF' => '0'],
        'namesilo' => ['DEF' => '0'],
    ];

    public static function getList()
    {
        return self::$dns_config;
    }

    private static function getConfig($aid)
    {
        $account = Db::name('account')->where('id', $aid)->find();
        if (!$account) return false;
        return $account;
    }

    /**
     * @return DnsInterface|bool
     */
    public static function getModel($aid, $domain = null, $domainid = null)
    {
        $config = self::getConfig($aid);
        if (!$config) return false;
        $dnstype = $config['type'];
        $class = "\\app\\lib\\dns\\{$dnstype}";
        if (class_exists($class)) {
            $config['domain'] = $domain;
            $config['domainid'] = $domainid;
            $model = new $class($config);
            return $model;
        }
        return false;
    }

    /**
     * @return DnsInterface|bool
     */
    public static function getModel2($config)
    {
        $dnstype = $config['type'];
        $class = "\\app\\lib\\dns\\{$dnstype}";
        if (class_exists($class)) {
            $config['domain'] = $config['name'];
            $config['domainid'] = $config['thirdid'];
            $model = new $class($config);
            return $model;
        }
        return false;
    }
}
