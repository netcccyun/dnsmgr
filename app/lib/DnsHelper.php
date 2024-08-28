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
                'sk' => 'AccessKeySecret'
            ],
            'remark' => 1, //是否支持备注，1单独设置备注，2和记录一起设置
            'status' => true, //是否支持启用暂停
            'redirect' => true, //是否支持域名转发
            'log' => true, //是否支持查看日志
        ],
        'dnspod' => [
            'name' => '腾讯云',
            'config' => [
                'ak' => 'SecretId',
                'sk' => 'SecretKey'
            ],
            'remark' => 1,
            'status' => true,
            'redirect' => true,
            'log' => true,
        ],
        'huawei' => [
            'name' => '华为云',
            'config' => [
                'ak' => 'AccessKeyId',
                'sk' => 'SecretAccessKey'
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
        ],
        'baidu' => [
            'name' => '百度云',
            'config' => [
                'ak' => 'AccessKey',
                'sk' => 'SecretKey'
            ],
            'remark' => 2,
            'status' => false,
            'redirect' => false,
            'log' => false,
        ],
        'west' => [
            'name' => '西部数码',
            'config' => [
                'ak' => '用户名',
                'sk' => 'API密码'
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => false,
            'log' => false,
        ],
        'huoshan' => [
            'name' => '火山引擎',
            'config' => [
                'ak' => 'AccessKeyId',
                'sk' => 'SecretAccessKey'
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
        ],
        'dnsla' => [
            'name' => 'DNSLA',
            'config' => [
                'ak' => 'APIID',
                'sk' => 'API密钥'
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => true,
            'log' => false,
        ],
        'cloudflare' => [
            'name' => 'Cloudflare',
            'config' => [
                'ak' => '邮箱地址',
                'sk' => 'API密钥/令牌'
            ],
            'remark' => 2,
            'status' => false,
            'redirect' => false,
            'log' => false,
        ],
    ];

    public static function getList()
    {
        return self::$dns_config;
    }

    private static function getConfig($aid){
        $account = Db::name('account')->where('id', $aid)->find();
        if(!$account) return false;
        return $account;
    }

    public static function getModel($aid, $domain = null, $domainid = null)
    {
        $config = self::getConfig($aid);
        if(!$config) return false;
        $dnstype = $config['type'];
        $class = "\\app\\lib\\dns\\{$dnstype}";
        if(class_exists($class)){
            $config['domain'] = $domain;
            $config['domainid'] = $domainid;
            $model = new $class($config);
            return $model;
        }
        return false;
    }

    public static function getModel2($config)
    {
        $dnstype = $config['type'];
        $class = "\\app\\lib\\dns\\{$dnstype}";
        if(class_exists($class)){
            $config['domain'] = $config['name'];
            $config['domainid'] = $config['thirdid'];
            $model = new $class($config);
            return $model;
        }
        return false;
    }
}