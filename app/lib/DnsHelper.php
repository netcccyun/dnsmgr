<?php

namespace app\lib;

use think\facade\Db;

class DnsHelper
{
    public static $dns_config = [
        'aliyun' => [
            'name' => '阿里云',
            'icon' => 'aliyun.png',
            'note' => '',
            'config' => [
                'AccessKeyId' => [
                    'name' => 'AccessKeyId',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'AccessKeySecret' => [
                    'name' => 'AccessKeySecret',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 1, //是否支持备注，1单独设置备注，2和记录一起设置
            'status' => true, //是否支持启用暂停
            'redirect' => true, //是否支持域名转发
            'log' => true, //是否支持查看日志
            'weight' => false, //是否支持权重
            'page' => false, //是否客户端分页
            'add' => true, //是否支持添加域名
        ],
        'dnspod' => [
            'name' => '腾讯云',
            'icon' => 'dnspod.ico',
            'note' => '',
            'config' => [
                'SecretId' => [
                    'name' => 'SecretId',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'SecretKey' => [
                    'name' => 'SecretKey',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 1,
            'status' => true,
            'redirect' => true,
            'log' => true,
            'weight' => true,
            'page' => false,
            'add' => true,
        ],
        'huawei' => [
            'name' => '华为云',
            'icon' => 'huawei.ico',
            'note' => '',
            'config' => [
                'AccessKeyId' => [
                    'name' => 'AccessKeyId',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'SecretAccessKey' => [
                    'name' => 'SecretAccessKey',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => true,
            'page' => false,
            'add' => true,
        ],
        'baidu' => [
            'name' => '百度云',
            'icon' => 'baidu.ico',
            'note' => '',
            'config' => [
                'AccessKeyId' => [
                    'name' => 'AccessKeyId',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'SecretAccessKey' => [
                    'name' => 'SecretAccessKey',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 2,
            'status' => false,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => true,
            'add' => true,
        ],
        'west' => [
            'name' => '西部数码',
            'icon' => 'west.ico',
            'note' => '',
            'config' => [
                'username' => [
                    'name' => '用户名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'api_password' => [
                    'name' => 'API密码',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => false,
            'add' => false,
        ],
        'huoshan' => [
            'name' => '火山引擎',
            'icon' => 'huoshan.ico',
            'note' => '',
            'config' => [
                'AccessKeyId' => [
                    'name' => 'AccessKeyId',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'SecretAccessKey' => [
                    'name' => 'SecretAccessKey',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => true,
            'page' => false,
            'add' => true,
        ],
        'jdcloud' => [
            'name' => '京东云',
            'icon' => 'jdcloud.ico',
            'note' => '',
            'config' => [
                'AccessKeyId' => [
                    'name' => 'AccessKeyId',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'AccessKeySecret' => [
                    'name' => 'AccessKeySecret',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => true,
            'log' => false,
            'weight' => true,
            'page' => false,
            'add' => true,
        ],
        'dnsla' => [
            'name' => 'DNSLA',
            'icon' => 'dnsla.ico',
            'note' => '',
            'config' => [
                'apiid' => [
                    'name' => 'APIID',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'apisecret' => [
                    'name' => 'API密钥',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => true,
            'log' => false,
            'weight' => true,
            'page' => false,
            'add' => true,
        ],
        'qingcloud' => [
            'name' => '青云',
            'icon' => 'qingcloud.ico',
            'note' => '',
            'config' => [
                'access_key_id' => [
                    'name' => 'Access Key ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'secret_access_key' => [
                    'name' => 'Secret Access Key',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => true,
            'page' => false,
            'add' => false,
        ],
        'bt' => [
            'name' => '宝塔域名',
            'icon' => 'bt.png',
            'note' => '',
            'config' => [
                'AccessKey' => [
                    'name' => 'Access Key',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'SecretKey' => [
                    'name' => 'Secret Key',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'AccountID' => [
                    'name' => 'Account ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => true,
            'page' => false,
            'add' => true,
        ],
        'cloudflare' => [
            'name' => 'Cloudflare',
            'icon' => 'cloudflare.ico',
            'note' => '',
            'config' => [
                'email' => [
                    'name' => '邮箱地址',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'apikey' => [
                    'name' => 'API密钥/令牌',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'auth' => [
                    'name' => '认证方式',
                    'type' => 'radio',
                    'options' => [
                        '0' => 'API密钥',
                        '1' => 'API令牌',
                    ],
                    'value' => '0'
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => false,
            'add' => true,
        ],
        'namesilo' => [
            'name' => 'NameSilo',
            'icon' => 'namesilo.ico',
            'note' => '',
            'config' => [
                'username' => [
                    'name' => '账户名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'apikey' => [
                    'name' => 'API Key',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 0,
            'status' => false,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => true,
            'add' => false,
        ],
        'spaceship' => [
            'name' => 'Spaceship',
            'icon' => 'spaceship.ico',
            'note' => '',
            'config' => [
                'apikey' => [
                    'name' => 'API Key',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'apisecret' => [
                    'name' => 'API Secret',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 0,
            'status' => false,
            'redirect' => true,
            'log' => false,
            'weight' => false,
            'page' => false,
            'add' => false,
        ],
        'powerdns' => [
            'name' => 'PowerDNS',
            'icon' => 'powerdns.ico',
            'note' => '',
            'config' => [
                'ip' => [
                    'name' => 'IP地址',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'port' => [
                    'name' => '端口',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'apikey' => [
                    'name' => 'API KEY',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 2,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => true,
            'add' => true,
        ],
        'aliyunesa' => [
            'name' => '阿里云ESA',
            'icon' => 'aliyun.png',
            'note' => '仅支持以NS方式接入阿里云ESA的域名',
            'config' => [
                'AccessKeyId' => [
                    'name' => 'AccessKeyId',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'AccessKeySecret' => [
                    'name' => 'AccessKeySecret',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'region' => [
                    'name' => 'API接入点',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'cn-hangzhou', 'label' => '中国内地'],
                        ['value' => 'ap-southeast-1', 'label' => '非中国内地'],
                    ],
                    'value' => 'cn-hangzhou',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 2,
            'status' => false,
            'redirect' => false,
            'log' => false,
            'weight' => false,
            'page' => false,
            'add' => false,
        ],
        'tencenteo' => [
            'name' => '腾讯云EO',
            'icon' => 'tencent.png',
            'note' => '仅支持以NS方式接入腾讯云EO的域名',
            'config' => [
                'SecretId' => [
                    'name' => 'SecretId',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'SecretKey' => [
                    'name' => 'SecretKey',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'site_type' => [
                    'name' => 'API接入点',
                    'type' => 'select',
                    'options' => [
                        ['value' => 'cn', 'label' => '中国内地'],
                        ['value' => 'intl', 'label' => '非中国内地'],
                    ],
                    'value' => 'cn',
                    'required' => true,
                ],
                'proxy' => [
                    'name' => '使用代理服务器',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0'
                ],
            ],
            'remark' => 0,
            'status' => true,
            'redirect' => false,
            'log' => false,
            'weight' => true,
            'page' => false,
            'add' => false,
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
        'jdcloud' => ['DEF' => '-1', 'CT' => '1', 'CU' => '2', 'CM' => '3', 'AB' => '4'],
        'bt' => ['DEF' => '0', 'CT' => '285344768', 'CU' => '285345792', 'CM' => '285346816'],
        'qingcloud' => ['DEF' => '0', 'CT' => '2', 'CU' => '3', 'CM' => '4', 'AB' => '8'],
        'cloudflare' => ['DEF' => '0'],
        'namesilo' => ['DEF' => 'default'],
        'powerdns' => ['DEF' => 'default'],
        'spaceship' => ['DEF' => 'default'],
        'aliyunesa' => ['DEF' => '0'],
        'tencenteo' => ['DEF' => 'Default'],
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
        $account = self::getConfig($aid);
        if (!$account) return false;
        $dnstype = $account['type'];
        $class = "\\app\\lib\\dns\\{$dnstype}";
        if (class_exists($class)) {
            $config = json_decode($account['config'], true);
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
    public static function getModel2($account)
    {
        $dnstype = $account['type'];
        $class = "\\app\\lib\\dns\\{$dnstype}";
        if (class_exists($class)) {
            $config = json_decode($account['config'], true);
            $config['domain'] = $account['name'];
            $config['domainid'] = $account['thirdid'];
            $model = new $class($config);
            return $model;
        }
        return false;
    }
}
