<?php

namespace app\lib;

use think\facade\Db;

class DeployHelper
{
    public static $deploy_config = [
        'btpanel' => [
            'name' => '宝塔面板',
            'class' => 1,
            'icon' => 'bt.png',
            'desc' => '支持部署到宝塔面板&aaPanel搭建的站点、Docker、邮局与面板本身',
            'note' => null,
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => '宝塔面板地址',
                    'note' => '填写规则如：http://192.168.1.100:8888 ，不要带其他后缀',
                    'required' => true,
                ],
                'key' => [
                    'name' => '接口密钥',
                    'type' => 'input',
                    'placeholder' => '宝塔面板设置->面板设置->API接口',
                    'required' => true,
                ],
                'version' => [
                    'name' => '面板版本',
                    'type' => 'radio',
                    'options' => [
                        '0' => 'Linux面板+Win经典版',
                        '1' => 'Win极速版',
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
            'taskinputs' => [
                'type' => [
                    'name' => '部署类型',
                    'type' => 'radio',
                    'options' => [
                        '0' => '网站的证书',
                        '3' => 'Docker网站的证书',
                        '2' => '邮局域名的证书',
                        '1' => '面板本身的证书',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'sites' => [
                    'name' => '网站名称列表',
                    'type' => 'textarea',
                    'placeholder' => '填写要部署证书的网站名称，每行一个',
                    'note' => 'PHP项目和反代项目填写创建时绑定的第一个域名，Java/Node/Go等其他项目填写项目名称，邮局和IIS站点填写绑定的域名',
                    'show' => 'type==0||type==2||type==3',
                    'required' => true,
                ],
                'is_iis' => [
                    'name' => '是否IIS站点',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'show' => 'type==0',
                    'value' => '0'
                ],
            ],
        ],
        'kangle' => [
            'name' => 'Kangle用户',
            'class' => 1,
            'icon' => 'host.png',
            'desc' => '支持虚拟主机与CDN站点',
            'note' => '以上登录信息为Easypanel用户面板的，非管理员面板。如选网站密码认证类型，则用户面板登录不能开启验证码。',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => 'Easypanel面板地址',
                    'note' => '填写规则如：http://192.168.1.100:3312 ，不要带其他后缀',
                    'required' => true,
                ],
                'auth' => [
                    'name' => '认证方式',
                    'type' => 'radio',
                    'options' => [
                        '0' => '网站密码',
                        '1' => '面板安全码',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'username' => [
                    'name' => '网站用户名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'password' => [
                    'name' => '网站密码',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'auth==0',
                    'required' => true,
                ],
                'skey' => [
                    'name' => '面板安全码',
                    'type' => 'input',
                    'placeholder' => '管理员面板->服务器设置->面板通信安全码',
                    'show' => 'auth==1',
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
            'taskinputs' => [
                'type' => [
                    'name' => '部署类型',
                    'type' => 'radio',
                    'options' => [
                        '0' => '网站SSL证书',
                        '1' => '单域名SSL证书（仅CDN支持）',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'domains' => [
                    'name' => 'CDN域名列表',
                    'type' => 'textarea',
                    'placeholder' => '填写要部署证书的域名，每行一个',
                    'show' => 'type==1',
                    'required' => true,
                ],
            ],
        ],
        'kangleadmin' => [
            'name' => 'Kangle管理员',
            'class' => 1,
            'icon' => 'host.png',
            'desc' => '支持虚拟主机与CDN站点',
            'note' => '以上登录信息为Easypanel管理员面板的，非用户面板。',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => 'Easypanel面板地址',
                    'note' => '填写规则如：http://192.168.1.100:3312 ，不要带其他后缀',
                    'required' => true,
                ],
                'path' => [
                    'name' => '管理员面板路径',
                    'type' => 'input',
                    'placeholder' => '留空默认为/admin',
                ],
                'username' => [
                    'name' => '管理员用户名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'skey' => [
                    'name' => '面板安全码',
                    'type' => 'input',
                    'placeholder' => '管理员面板->服务器设置->面板通信安全码',
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
            'taskinputs' => [
                'name' => [
                    'name' => '网站用户名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'type' => [
                    'name' => '部署类型',
                    'type' => 'radio',
                    'options' => [
                        '0' => '网站SSL证书',
                        '1' => '单域名SSL证书（仅CDN支持）',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'domains' => [
                    'name' => 'CDN域名列表',
                    'type' => 'textarea',
                    'placeholder' => '填写要部署证书的域名，每行一个',
                    'show' => 'type==1',
                    'required' => true,
                ],
            ],
        ],
        'safeline' => [
            'name' => '雷池WAF',
            'class' => 1,
            'icon' => 'safeline.png',
            'desc' => '',
            'note' => null,
            'tasknote' => '系统会根据关联SSL证书的域名，自动更新对应证书',
            'inputs' => [
                'url' => [
                    'name' => '控制台地址',
                    'type' => 'input',
                    'placeholder' => '雷池WAF控制台地址',
                    'note' => '填写规则如：https://192.168.1.100:9443 ，不要带其他后缀',
                    'required' => true,
                ],
                'token' => [
                    'name' => 'API Token',
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
            'taskinputs' => [],
        ],
        'btwaf' => [
            'name' => '堡塔云WAF',
            'class' => 1,
            'icon' => 'bt.png',
            'desc' => '',
            'note' => null,
            'tasknote' => '',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => '堡塔云WAF面板地址',
                    'note' => '填写规则如：http://192.168.1.100:8379 ，不要带其他后缀',
                    'required' => true,
                ],
                'key' => [
                    'name' => '接口密钥',
                    'type' => 'input',
                    'placeholder' => '面板设置->API接口',
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
            'taskinputs' => [
                'type' => [
                    'name' => '部署类型',
                    'type' => 'radio',
                    'options' => [
                        '0' => '网站的证书',
                        '1' => '面板本身的证书',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'sites' => [
                    'name' => '网站名称列表',
                    'type' => 'textarea',
                    'placeholder' => '填写要部署证书的网站名称，每行一个',
                    'required' => true,
                    'show' => 'type==0',
                ],
            ],
        ],
        'cdnfly' => [
            'name' => 'Cdnfly',
            'class' => 1,
            'icon' => 'waf.png',
            'desc' => '',
            'note' => '登录Cdnfly控制台->账户中心->API密钥，点击开启后获取',
            'inputs' => [
                'url' => [
                    'name' => '控制台地址',
                    'type' => 'input',
                    'placeholder' => 'Cdnfly控制台地址',
                    'note' => '填写示例：http://demo.cdnfly.cn',
                    'required' => true,
                ],
                'auth' => [
                    'name' => '认证方式',
                    'type' => 'radio',
                    'options' => [
                        '0' => '接口密钥',
                        '1' => '模拟登录',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'api_key' => [
                    'name' => 'api_key',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                    'show' => 'auth==0',
                ],
                'api_secret' => [
                    'name' => 'api_secret',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                    'show' => 'auth==0',
                ],
                'username' => [
                    'name' => '登录账号',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                    'show' => 'auth==1',
                ],
                'password' => [
                    'name' => '登录密码',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                    'show' => 'auth==1',
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
            'taskinputs' => [
                'id' => [
                    'name' => '证书ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在网站管理->证书管理查看证书的ID，注意域名是否与证书匹配',
                    'required' => true,
                ],
            ],
        ],
        'lecdn' => [
            'name' => 'LeCDN',
            'class' => 1,
            'icon' => 'waf.png',
            'desc' => '',
            'note' => null,
            'inputs' => [
                'url' => [
                    'name' => '控制台地址',
                    'type' => 'input',
                    'placeholder' => 'LeCDN控制台地址',
                    'note' => '填写示例：http://demo.xxxx.cn',
                    'required' => true,
                ],
                'auth' => [
                    'name' => '认证方式',
                    'type' => 'radio',
                    'options' => [
                        '0' => '账号密码(旧版)',
                        '1' => 'API访问令牌',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'api_key' => [
                    'name' => 'API访问令牌',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                    'show' => 'auth==1',
                ],
                'email' => [
                    'name' => '邮箱地址',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                    'show' => 'auth==0',
                ],
                'password' => [
                    'name' => '密码',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                    'show' => 'auth==0',
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
            'taskinputs' => [
                'id' => [
                    'name' => '证书ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在站点->证书管理查看证书的ID，注意域名是否与证书匹配',
                    'required' => true,
                ],
            ],
        ],
        'goedge' => [
            'name' => 'GoEdge',
            'class' => 1,
            'icon' => 'waf.png',
            'desc' => '支持GoEdge与FlexCDN',
            'note' => '需要先<a href="https://goedge.cloud/docs/API/Settings.md" target="_blank" rel="noreferrer">开启HTTP API端口</a>',
            'tasknote' => '系统会根据关联SSL证书的域名，自动更新对应证书',
            'inputs' => [
                'url' => [
                    'name' => 'HTTP API地址',
                    'type' => 'input',
                    'placeholder' => 'HTTP API地址',
                    'note' => 'http://你的IP:端口',
                    'required' => true,
                ],
                'accessKeyId' => [
                    'name' => 'AccessKey ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'accessKey' => [
                    'name' => 'AccessKey密钥',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'usertype' => [
                    'name' => '用户类型',
                    'type' => 'radio',
                    'options' => [
                        'user' => '平台用户',
                        'admin' => '系统用户',
                    ],
                    'value' => 'user',
                    'required' => true,
                ],
                'systype' => [
                    'name' => '系统类型',
                    'type' => 'radio',
                    'options' => [
                        '0' => 'GoEdge',
                        '1' => 'FlexCDN',
                    ],
                    'value' => '0',
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
            'taskinputs' => [],
        ],
        'uusec' => [
            'name' => '南墙WAF',
            'class' => 1,
            'icon' => 'waf.png',
            'desc' => '',
            'note' => null,
            'inputs' => [
                'url' => [
                    'name' => '控制台地址',
                    'type' => 'input',
                    'placeholder' => '南墙WAF控制台地址',
                    'note' => '填写规则如：http://192.168.1.100:4443 ，不要带其他后缀',
                    'required' => true,
                ],
                'username' => [
                    'name' => '用户名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'password' => [
                    'name' => '密码',
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
            'taskinputs' => [
                'id' => [
                    'name' => '证书ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在证书管理查看证书的ID，注意域名是否与证书匹配',
                    'required' => true,
                ],
                'name' => [
                    'name' => '证书名称',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在证书管理查看证书的名称',
                    'required' => true,
                ],
            ],
        ],
        'opanel' => [
            'name' => '1Panel',
            'class' => 1,
            'icon' => 'opanel.png',
            'desc' => '更新面板证书管理内的SSL证书',
            'note' => null,
            'tasknote' => '系统会根据关联SSL证书的域名，自动更新对应证书',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => '1Panel面板地址',
                    'note' => '填写规则如：http://192.168.1.100:8888 ，不要带其他后缀',
                    'required' => true,
                ],
                'key' => [
                    'name' => '接口密钥',
                    'type' => 'input',
                    'placeholder' => '1Panel面板设置->API接口',
                    'required' => true,
                ],
                'version' => [
                    'name' => '1Panel版本',
                    'type' => 'radio',
                    'options' => [
                        'v1' => '1.x',
                        'v2' => '2.x',
                    ],
                    'value' => 'v1',
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
            'taskinputs' => [],
        ],
        'mwpanel' => [
            'name' => 'MW面板',
            'class' => 1,
            'icon' => 'mwpanel.ico',
            'desc' => '',
            'note' => null,
            'tasknote' => '',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => 'MW面板地址',
                    'note' => '填写规则如：http://192.168.1.100:8888 ，不要带其他后缀',
                    'required' => true,
                ],
                'appid' => [
                    'name' => '应用ID',
                    'type' => 'input',
                    'placeholder' => 'MW面板设置->API接口',
                    'required' => true,
                ],
                'appsecret' => [
                    'name' => '应用密钥',
                    'type' => 'input',
                    'placeholder' => '面板设置->API接口',
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
            'taskinputs' => [
                'type' => [
                    'name' => '部署类型',
                    'type' => 'radio',
                    'options' => [
                        '0' => 'MW面板站点的证书',
                        '1' => 'MW面板本身的证书',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'sites' => [
                    'name' => '网站名称列表',
                    'type' => 'textarea',
                    'placeholder' => '填写要部署证书的网站名称，每行一个',
                    'note' => '网站名称，即为网站创建时绑定的第一个域名',
                    'show' => 'type==0',
                    'required' => true,
                ],
            ],
        ],
        'ratpanel' => [
            'name' => '耗子面板',
            'class' => 1,
            'icon' => 'ratpanel.ico',
            'desc' => '支持耗子面板 v2.5+ 版本使用',
            'note' => '支持耗子面板 v2.5+ 版本使用',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => '耗子面板地址',
                    'note' => '填写规则如：https://192.168.1.100:8888/xxxxxx ，带访问入口但不要带其他后缀',
                    'required' => true,
                ],
                'id' => [
                    'name' => '访问令牌ID',
                    'type' => 'input',
                    'placeholder' => '1',
                    'note' => '耗子面板设置->用户->访问令牌',
                    'required' => true,
                ],
                'token' => [
                    'name' => '访问令牌',
                    'type' => 'input',
                    'note' => '耗子面板设置->用户->访问令牌',
                    'placeholder' => '32位字符串',
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
            'taskinputs' => [
                'type' => [
                    'name' => '部署类型',
                    'type' => 'radio',
                    'options' => [
                        '0' => '耗子面板网站的证书',
                        '1' => '耗子面板本身的证书',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'sites' => [
                    'name' => '网站名称列表',
                    'type' => 'textarea',
                    'placeholder' => '填写要部署证书的网站名称，每行一个',
                    'note' => '填写创建网站时设置的网站唯一名称',
                    'show' => 'type==0',
                    'required' => true,
                ],
            ],
        ],
        'xp' => [
            'name' => '小皮面板',
            'class' => 1,
            'icon' => 'xp.png',
            'desc' => '',
            'note' => null,
            'tasknote' => '',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => '小皮面板地址',
                    'note' => '填写规则如：http://192.168.1.100:8888 ，不要带其他后缀',
                    'required' => true,
                ],
                'apikey' => [
                    'name' => '接口密钥',
                    'type' => 'input',
                    'placeholder' => '设置->OpenAPI接口',
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
            'taskinputs' => [
                'sites' => [
                    'name' => '网站名称列表',
                    'type' => 'textarea',
                    'placeholder' => '填写要部署证书的网站名称，每行一个',
                    'note' => '网站名称，即为网站创建时绑定的第一个域名',
                    'required' => true,
                ],
            ],
        ],
        'synology' => [
            'name' => '群晖面板',
            'class' => 1,
            'icon' => 'synology.png',
            'desc' => '支持群晖DSM 6.x/7.x版本',
            'note' => null,
            'tasknote' => '',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => '群晖面板地址',
                    'note' => '填写规则如：http://192.168.1.100:5000 ，不要带其他后缀',
                    'required' => true,
                ],
                'username' => [
                    'name' => '登录账号',
                    'type' => 'input',
                    'placeholder' => '必须是处于管理员用户组，不能开启双重认证',
                    'required' => true,
                ],
                'password' => [
                    'name' => '登录密码',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'version' => [
                    'name' => '群晖版本',
                    'type' => 'radio',
                    'options' => [
                        '0' => '7.x',
                        '1' => '6.x',
                    ],
                    'value' => '0',
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
            'taskinputs' => [
                'desc' => [
                    'name' => '群晖证书描述',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '根据证书描述匹配替换对应证书，留空则根据证书通用名匹配',
                ],
            ],
        ],
        'lucky' => [
            'name' => 'Lucky',
            'class' => 1,
            'icon' => 'lucky.png',
            'desc' => '更新Lucky证书',
            'note' => '在“设置->开发者设置”打开OpenToken开关',
            'tasknote' => '系统会根据关联SSL证书的域名，自动更新对应证书',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => 'Lucky 面板地址',
                    'note' => '填写规则如：https://192.168.1.100:16601 ，不要带其他后缀',
                    'required' => true,
                ],
                'path' => [
                    'name' => '安全入口',
                    'type' => 'input',
                    'note' => '未设置请留空，参考Lucky设置中的安全入口设置'
                ],
                'opentoken' => [
                    'name' => 'OpenToken',
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
            'taskinputs' => [],
        ],
        'fnos' => [
            'name' => '飞牛OS',
            'class' => 1,
            'icon' => 'fnos.png',
            'desc' => '更新飞牛OS的证书',
            'note' => '请先配置sudo免密：<br/>
sudo visudo<br/>
#在文件最后一行增加以下内容，需要将username替换成自己的用户名<br/>
username ALL=(ALL) NOPASSWD: NOPASSWD: ALL<br/>
ctrl+x 保存退出',
            'tasknote' => '系统会根据关联SSL证书的域名，自动更新对应证书',
            'inputs' => [
                'host' => [
                    'name' => '主机地址',
                    'type' => 'input',
                    'placeholder' => '填写IP地址或域名，需开启SSH功能',
                    'required' => true,
                ],
                'port' => [
                    'name' => 'SSH端口',
                    'type' => 'input',
                    'placeholder' => '',
                    'value' => '22',
                    'required' => true,
                ],
                'username' => [
                    'name' => '用户名',
                    'type' => 'input',
                    'placeholder' => '登录用户名',
                    'value' => '',
                    'required' => true,
                ],
                'password' => [
                    'name' => '密码',
                    'type' => 'input',
                    'placeholder' => '登录密码',
                    'required' => true,
                ],
            ],
            'taskinputs' => [],
        ],
        'proxmox' => [
            'name' => 'Proxmox VE',
            'class' => 1,
            'icon' => 'proxmox.ico',
            'desc' => '部署到PVE节点',
            'note' => '在“权限->API令牌”添加令牌，不要选特权分离',
            'tasknote' => '',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => 'Proxmox VE 面板地址',
                    'note' => '填写规则如：https://192.168.1.100:8006 ，不要带其他后缀',
                    'required' => true,
                ],
                'api_user' => [
                    'name' => 'API令牌ID',
                    'type' => 'input',
                    'placeholder' => '用户!令牌名称',
                    'required' => true,
                ],
                'api_key' => [
                    'name' => 'API令牌密钥',
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
            'taskinputs' => [
                'node' => [
                    'name' => '节点名称',
                    'type' => 'input',
                    'placeholder' => '要部署证书的节点',
                    'required' => true,
                ],
            ],
        ],
        'aliyun' => [
            'name' => '阿里云',
            'class' => 2,
            'icon' => 'aliyun.png',
            'desc' => '支持部署到阿里云CDN、ESA、SLB、OSS、WAF、FC等服务',
            'note' => '支持部署到阿里云CDN、ESA、SLB、OSS、WAF、FC等服务',
            'tasknote' => '',
            'inputs' => [
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cdn', 'label'=>'内容分发CDN'],
                        ['value'=>'dcdn', 'label'=>'全站加速DCDN'],
                        ['value'=>'esa', 'label'=>'边缘安全加速ESA'],
                        ['value'=>'oss', 'label'=>'对象存储OSS'],
                        ['value'=>'waf', 'label'=>'Web应用防火墙3.0'],
                        ['value'=>'waf2', 'label'=>'Web应用防火墙2.0'],
                        ['value'=>'clb', 'label'=>'传统型负载均衡CLB'],
                        ['value'=>'alb', 'label'=>'应用型负载均衡ALB'],
                        ['value'=>'nlb', 'label'=>'网络型负载均衡NLB'],
                        ['value'=>'api', 'label'=>'API网关'],
                        ['value'=>'ddoscoo', 'label'=>'DDoS高防'],
                        ['value'=>'live', 'label'=>'视频直播'],
                        ['value'=>'vod', 'label'=>'视频点播'],
                        ['value'=>'fc', 'label'=>'函数计算3.0'],
                        ['value'=>'fc2', 'label'=>'函数计算2.0'],
                    ],
                    'value' => 'cdn',
                    'required' => true,
                ],
                'esa_sitename' => [
                    'name' => 'ESA站点域名',
                    'type' => 'input',
                    'placeholder' => 'ESA添加的站点主域名',
                    'show' => 'product==\'esa\'',
                    'required' => true,
                ],
                'oss_endpoint' => [
                    'name' => 'Endpoint地址',
                    'type' => 'input',
                    'placeholder' => '填写示例：oss-cn-hangzhou.aliyuncs.com',
                    'show' => 'product==\'oss\'',
                    'required' => true,
                ],
                'oss_bucket' => [
                    'name' => 'Bucket名称',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'oss\'',
                    'required' => true,
                ],
                'region' => [
                    'name' => '所属地域',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cn-hangzhou', 'label'=>'中国内地'],
                        ['value'=>'ap-southeast-1', 'label'=>'非中国内地'],
                    ],
                    'value' => 'cn-hangzhou',
                    'show' => 'product==\'waf\'||product==\'waf2\'||product==\'ddoscoo\'||product==\'esa\'',
                    'required' => true,
                ],
                'regionid' => [
                    'name' => '所属地域ID',
                    'type' => 'input',
                    'placeholder' => '填写示例：cn-hangzhou',
                    'show' => 'product==\'api\'||product==\'clb\'||product==\'alb\'||product==\'nlb\'',
                    'value' => 'cn-hangzhou',
                    'required' => true,
                ],
                'api_groupid' => [
                    'name' => 'API分组ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'api\'',
                    'required' => true,
                ],
                'fc_cname' => [
                    'name' => '域名CNAME地址',
                    'type' => 'input',
                    'placeholder' => '填写示例：<账号ID>.cn-shanghai.fc.aliyuncs.com',
                    'show' => 'product==\'fc\'||product==\'fc2\'',
                    'required' => true,
                ],
                'clb_id' => [
                    'name' => '负载均衡实例ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'clb\'',
                    'required' => true,
                ],
                'clb_port' => [
                    'name' => 'HTTPS监听端口',
                    'type' => 'input',
                    'placeholder' => '',
                    'value' => '443',
                    'show' => 'product==\'clb\'',
                    'required' => true,
                ],
                'alb_listener_id' => [
                    'name' => '监听ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'alb\'',
                    'note' => '进入ALB实例详情->监听列表，复制监听ID（只支持HTTPS或QUIC监听协议）',
                    'required' => true,
                ],
                'nlb_listener_id' => [
                    'name' => '监听ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'nlb\'',
                    'note' => '进入NLB实例详情->监听列表，复制监听ID（只支持TCPSSL监听协议）',
                    'required' => true,
                ],
                'deploy_type' => [
                    'name' => '部署证书类型',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'0', 'label'=>'默认证书'],
                        ['value'=>'1', 'label'=>'扩展证书'],
                    ],
                    'value' => '0',
                    'show' => 'product==\'clb\'||product==\'alb\'||product==\'nlb\'',
                    'required' => true,
                ],
                'clb_domain' => [
                    'name' => '扩展域名',
                    'type' => 'input',
                    'placeholder' => '多个域名可使用,分隔',
                    'show' => 'product==\'clb\'&&deploy_type==1',
                    'required' => true,
                ],
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product!=\'esa\'&&product!=\'clb\'&&product!=\'alb\'&&product!=\'nlb\'',
                    'required' => true,
                ],
            ],
        ],
        'tencent' => [
            'name' => '腾讯云',
            'class' => 2,
            'icon' => 'tencent.png',
            'desc' => '支持部署到腾讯云CDN、EO、CLB、COS、TKE、SCF等服务',
            'note' => '支持部署到腾讯云CDN、EO、CLB、COS、TKE、SCF等服务',
            'tasknote' => '',
            'inputs' => [
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cdn', 'label'=>'内容分发网络CDN'],
                        ['value'=>'teo', 'label'=>'边缘安全加速EO'],
                        ['value'=>'waf', 'label'=>'Web应用防火墙WAF'],
                        ['value'=>'cos', 'label'=>'对象存储COS'],
                        ['value'=>'clb', 'label'=>'负载均衡CLB'],
                        ['value'=>'tke', 'label'=>'容器服务TKE'],
                        ['value'=>'scf', 'label'=>'云函数SCF'],
                        ['value'=>'ddos', 'label'=>'DDoS防护'],
                        ['value'=>'live', 'label'=>'云直播LIVE'],
                        ['value'=>'vod', 'label'=>'云点播VOD'],
                        ['value'=>'tse', 'label'=>'云原生API网关TSE'],
                        ['value'=>'tcb', 'label'=>'云开发TCB'],
                        ['value'=>'lighthouse', 'label'=>'轻量应用服务器'],
                    ],
                    'value' => 'cdn',
                    'required' => true,
                ],
                'regionid' => [
                    'name' => '所属地域ID',
                    'type' => 'input',
                    'placeholder' => '填写示例：ap-guangzhou',
                    'show' => 'product==\'clb\'||product==\'cos\'||product==\'tse\'||product==\'tke\'||product==\'lighthouse\'||product==\'scf\'',
                    'value' => '',
                    'required' => true,
                ],
                'region' => [
                    'name' => '所属地域',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'ap-guangzhou', 'label'=>'中国大陆'],
                        ['value'=>'ap-seoul', 'label'=>'非中国大陆'],
                    ],
                    'value' => 'ap-guangzhou',
                    'show' => 'product==\'waf\'',
                    'required' => true,
                ],
                'clb_id' => [
                    'name' => '负载均衡ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'clb\'',
                    'required' => true,
                ],
                'clb_listener_id' => [
                    'name' => '监听器ID',
                    'type' => 'input',
                    'placeholder' => '可留空，会自动根据域名或负载均衡ID查找',
                    'show' => 'product==\'clb\'',
                ],
                'clb_domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '若监听器开启SNI，则域名必填；若关闭SNI，则域名留空',
                    'show' => 'product==\'clb\'',
                ],
                'tke_cluster_id' => [
                    'name' => '集群ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'tke\'',
                    'required' => true,
                ],
                'tke_namespace' => [
                    'name' => '命名空间',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'tke\'',
                    'required' => true,
                ],
                'tke_secret' => [
                    'name' => '证书的secret名称',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'tke\'',
                    'required' => true,
                ],
                'cos_bucket' => [
                    'name' => '存储桶名称',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'cos\'',
                    'required' => true,
                ],
                'lighthouse_id' => [
                    'name' => '实例ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'lighthouse\'||product==\'ddos\'',
                    'required' => true,
                ],
                'site_type' => [
                    'name' => '站点类型',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cn', 'label'=>'国内站'],
                        ['value'=>'intl', 'label'=>'国际站'],
                    ],
                    'value' => 'cn',
                    'show' => 'product==\'teo\'',
                    'required' => true,
                ],
                'site_id' => [
                    'name' => '站点ID',
                    'type' => 'input',
                    'placeholder' => '类似于zone-xxxx，在站点列表或概览页面查看',
                    'show' => 'product==\'teo\'',
                    'required' => true,
                ],
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product!=\'clb\'&&product!=\'tke\'',
                    'note' => 'CDN、EO、WAF多个域名可用,隔开，其他只能填写1个域名',
                    'required' => true,
                ],
            ],
        ],
        'huawei' => [
            'name' => '华为云',
            'class' => 2,
            'icon' => 'huawei.ico',
            'desc' => '支持部署到华为云CDN、ELB、WAF等服务',
            'note' => '支持部署到华为云CDN、ELB、WAF等服务',
            'inputs' => [
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cdn', 'label'=>'内容分发网络CDN'],
                        ['value'=>'elb', 'label'=>'弹性负载均衡ELB'],
                        ['value'=>'waf', 'label'=>'Web应用防火墙WAF'],
                    ],
                    'value' => 'cdn',
                    'required' => true,
                ],
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '多个域名可使用,分隔',
                    'show' => 'product==\'cdn\'',
                    'required' => true,
                ],
                'project_id' => [
                    'name' => '项目ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'elb\'||product==\'waf\'',
                    'note' => '项目ID可在我的凭证->项目列表页面查看',
                    'required' => true,
                ],
                'region_id' => [
                    'name' => '区域ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'elb\'||product==\'waf\'',
                    'note' => '区域ID可在<a href="https://console.huaweicloud.com/apiexplorer/#/endpoint" target="_blank" rel="noreferrer">此页面查找</a>',
                    'required' => true,
                ],
                'cert_id' => [
                    'name' => '要更新的证书ID',
                    'type' => 'input',
                    'placeholder' => '在ELB控制台->证书管理->查看已上传的证书ID',
                    'show' => 'product==\'elb\'||product==\'waf\'',
                    'required' => true,
                ],
            ],
        ],
        'ucloud' => [
            'name' => 'UCloud',
            'class' => 2,
            'icon' => 'ucloud.ico',
            'desc' => '支持部署到UCDN',
            'note' => '支持部署到UCDN',
            'inputs' => [
                'PublicKey' => [
                    'name' => '公钥',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'PrivateKey' => [
                    'name' => '私钥',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
            ],
            'taskinputs' => [
                'domain_id' => [
                    'name' => '云分发资源ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在云分发-域名管理-域名基本信息页面查看',
                    'required' => true,
                ],
            ],
        ],
        'qiniu' => [
            'name' => '七牛云',
            'class' => 2,
            'icon' => 'qiniu.ico',
            'desc' => '支持部署到七牛云CDN、OSS',
            'note' => '支持部署到七牛云CDN、OSS',
            'inputs' => [
                'AccessKey' => [
                    'name' => 'AccessKey',
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cdn', 'label'=>'CDN'],
                        ['value'=>'oss', 'label'=>'OSS'],
                        ['value'=>'pili', 'label'=>'视频直播'],
                    ],
                    'value' => 'cdn',
                    'required' => true,
                ],
                'pili_hub' => [
                    'name' => '直播空间名称',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'pili\'',
                    'required' => true,
                ],
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '多个域名可使用,分隔',
                    'required' => true,
                ],
            ],
        ],
        'doge' => [
            'name' => '多吉云',
            'class' => 2,
            'icon' => 'doge.png',
            'desc' => '支持部署到多吉云融合CDN',
            'note' => '支持部署到多吉云融合CDN',
            'inputs' => [
                'AccessKey' => [
                    'name' => 'AccessKey',
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
            'taskinputs' => [
                'domain' => [
                    'name' => 'CDN域名',
                    'type' => 'input',
                    'placeholder' => '多个域名可使用,分隔',
                    'required' => true,
                ],
            ],
        ],
        'upyun' => [
            'name' => '又拍云',
            'class' => 2,
            'icon' => 'upyun.ico',
            'desc' => '支持部署到又拍云CDN',
            'note' => '支持部署到又拍云CDN',
            'tasknote' => '系统会根据关联SSL证书的域名，进行证书的迁移操作',
            'inputs' => [
                'username' => [
                    'name' => '用户名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'password' => [
                    'name' => '密码',
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
        ],
        'baidu' => [
            'name' => '百度云',
            'class' => 2,
            'icon' => 'baidu.ico',
            'desc' => '支持部署到百度云CDN、BLB',
            'note' => '支持部署到百度云CDN、BLB',
            'inputs' => [
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cdn', 'label'=>'CDN'],
                        ['value'=>'blb', 'label'=>'普通型BLB'],
                        ['value'=>'appblb', 'label'=>'应用型BLB'],
                    ],
                    'value' => 'cdn',
                    'required' => true,
                ],
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '多个域名可使用,分隔',
                    'show' => 'product==\'cdn\'',
                    'required' => true,
                ],
                'region' => [
                    'name' => '所属地域',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'bj', 'label'=>'北京'],
                        ['value'=>'gz', 'label'=>'广州'],
                        ['value'=>'su', 'label'=>'苏州'],
                        ['value'=>'hkg', 'label'=>'香港'],
                        ['value'=>'fwh', 'label'=>'武汉'],
                        ['value'=>'bd', 'label'=>'保定'],
                        ['value'=>'fsh', 'label'=>'上海'],
                        ['value'=>'sin', 'label'=>'新加坡'],
                    ],
                    'value' => 'bj',
                    'show' => 'product==\'blb\'||product==\'appblb\'',
                    'required' => true,
                ],
                'blb_id' => [
                    'name' => '负载均衡实例ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'blb\'||product==\'appblb\'',
                    'required' => true,
                ],
                'blb_port' => [
                    'name' => 'HTTPS监听端口',
                    'type' => 'input',
                    'placeholder' => '',
                    'value' => '443',
                    'show' => 'product==\'blb\'||product==\'appblb\'',
                    'required' => true,
                ],
            ],
        ],
        'huoshan' => [
            'name' => '火山引擎',
            'class' => 2,
            'icon' => 'huoshan.ico',
            'desc' => '支持部署到火山引擎CDN、CLB、TOS、直播、veImageX',
            'note' => '支持部署到火山引擎CDN、CLB、TOS、直播、veImageX',
            'inputs' => [
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cdn', 'label'=>'内容分发网络CDN'],
                        ['value'=>'dcdn', 'label'=>'全站加速DCDN'],
                        ['value'=>'clb', 'label'=>'负载均衡CLB'],
                        ['value'=>'alb', 'label'=>'应用型负载均衡ALB'],
                        ['value'=>'tos', 'label'=>'对象存储TOS'],
                        ['value'=>'live', 'label'=>'视频直播'],
                        ['value'=>'imagex', 'label'=>'veImageX'],
                    ],
                    'value' => 'cdn',
                    'required' => true,
                ],
                'bucket_domain' => [
                    'name' => 'Bucket域名',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'tos\'',
                    'required' => true,
                ],
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '多个域名可使用,分隔',
                    'show' => 'product!=\'clb\'&&product!=\'alb\'',
                    'required' => true,
                ],
                'listener_id' => [
                    'name' => '监听器ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'clb\'||product==\'alb\'',
                    'required' => true,
                ],
            ],
        ],
        'west' => [
            'name' => '西部数码',
            'class' => 2,
            'icon' => 'west.ico',
            'desc' => '支持部署到西部数码虚拟主机',
            'note' => '支持部署到西部数码虚拟主机',
            'inputs' => [
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
            'taskinputs' => [
                'sitename' => [
                    'name' => 'FTP账号',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
            ],
        ],
        'wangsu' => [
            'name' => '网宿科技',
            'class' => 2,
            'icon' => 'wangsu.ico',
            'desc' => '支持部署到网宿CDN',
            'note' => '适用产品：网页加速、下载分发、全站加速、点播分发、直播分发、上传加速、移动加速、上网加速、S-P2P、PCDN、应用性能管理、WEB应用防火墙、BotGuard爬虫管理、WSS、DMS、DDoS云清洗、应用加速、应用安全加速解决方案、IPv6一体化解决方案、电商安全加速解决方案、金融安全加速解决方案、政企安全加速解决方案、DDoS云清洗(非网站业务)、区块链安全加速解决方案、IPv6安全加速解决方案、CDN Pro。暂不支持AKSK鉴权。',
            'inputs' => [
                'username' => [
                    'name' => '账号',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'apiKey' => [
                    'name' => 'APIKEY',
                    'type' => 'input',
                    'placeholder' => '自行联系提供商申请',
                    'required' => true,
                ],
                'spKey' => [
                    'name' => '特殊KEY',
                    'type' => 'input',
                    'placeholder' => '特殊场景下才需要使用的APIKEY，留空默认同APIKEY',
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cdn', 'label'=>'CDN'],
                        ['value'=>'cdnpro', 'label'=>'CDN Pro'],
                        ['value'=>'certificate', 'label'=>'证书管理']
                    ],
                    'value' => 'cdn',
                    'required' => true,
                ],
                'domains' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'show' => 'product==\'cdn\'',
                    'placeholder' => '多个域名可使用,分隔',
                    'required' => true,
                ],
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'show' => 'product==\'cdnpro\'',
                    'placeholder' => '不支持输入多个域名',
                    'required' => true,
                ],
                'cert_id' => [
                    'name' => '证书ID',
                    'type' => 'input',
                    'show' => 'product==\'certificate\'',
                    'placeholder' => '',
                    'required' => true,
                ],
            ],
        ],
        'baishan' => [
            'name' => '白山云',
            'class' => 2,
            'icon' => 'waf.png',
            'desc' => '替换白山云证书管理内的证书',
            'note' => null,
            'inputs' => [
                'account' => [
                    'name' => '账户名',
                    'type' => 'input',
                    'placeholder' => '仅用作标记',
                    'required' => true,
                ],
                'token' => [
                    'name' => 'token',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '自行联系提供商申请',
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
            'taskinputs' => [
                'id' => [
                    'name' => '证书ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在证书管理页面查看，注意域名是否与证书匹配',
                    'required' => true,
                ],
            ],
        ],
        'ctyun' => [
            'name' => '天翼云',
            'class' => 2,
            'icon' => 'ctyun.ico',
            'desc' => '支持部署到天翼云CDN、边缘加速',
            'note' => '支持部署到天翼云CDN、边缘加速',
            'inputs' => [
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cdn', 'label'=>'CDN加速'],
                        ['value'=>'icdn', 'label'=>'全站加速'],
                        ['value'=>'accessone', 'label'=>'边缘安全加速平台'],
                    ],
                    'value' => 'cdn',
                    'required' => true,
                ],
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
            ],
        ],
        'kuocai' => [
            'name' => '括彩云',
            'class' => 2,
            'icon' => 'kuocai.jpg',
            'desc' => '替换括彩云证书管理内的证书',
            'note' => '支持括彩云及其代理商，填写控制台登录账号及密码',
            'inputs' => [
                'username' => [
                    'name' => '账号',
                    'type' => 'input',
                    'placeholder' => '控制台账号',
                    'note' => '填写手机号或邮箱',
                    'required' => true,
                ],
                'password' => [
                    'name' => '密码',
                    'type' => 'input',
                    'placeholder' => '控制台密码',
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
            'taskinputs' => [
                'id' => [
                    'name' => '域名ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在控制台->我的域名->配置复制浏览器地址栏显示的域名ID（19位数字），注意域名是否与证书匹配',
                    'required' => true,
                ],
            ],
        ],
        'rainyun' => [
            'name' => '雨云',
            'class' => 2,
            'icon' => 'waf.png',
            'desc' => '替换雨云证书管理内的证书',
            'note' => null,
            'inputs' => [
                'account' => [
                    'name' => '账号',
                    'type' => 'input',
                    'placeholder' => '仅用作标记',
                    'required' => true,
                ],
                'apikey' => [
                    'name' => 'ApiKey',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在 账户设置->API密钥 页面查看',
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
            'taskinputs' => [
                'id' => [
                    'name' => '证书ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在SSL证书->我的证书页面查看，注意域名是否与证书匹配',
                    'required' => true,
                ],
            ],
        ],
        'unicloud' => [
            'name' => 'uniCloud',
            'class' => 2,
            'icon' => 'unicloud.png',
            'desc' => '部署到uniCloud服务空间',
            'note' => null,
            'inputs' => [
                'username' => [
                    'name' => '账号',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'password' => [
                    'name' => '密码',
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
            'taskinputs' => [
                'spaceId' => [
                    'name' => '服务空间ID',
                    'type' => 'input',
                    'placeholder' => 'spaceId',
                    'required' => true,
                ],
                'provider' => [
                    'name' => '空间提供商',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'aliyun', 'label'=>'阿里云'],
                        ['value'=>'tencent', 'label'=>'腾讯云'],
                        ['value'=>'alipay', 'label'=>'支付宝云'],
                    ],
                    'value' => 'aliyun',
                    'required' => true,
                ],
                'domains' => [
                    'name' => '空间域名',
                    'type' => 'input',
                    'placeholder' => '多个域名可使用,分隔',
                    'required' => true,
                ],
            ],
        ],
        'aws' => [
            'name' => 'AWS',
            'class' => 2,
            'icon' => 'aws.png',
            'desc' => '支持部署到Amazon CloudFront、AWS Certificate Manager',
            'note' => '支持部署到Amazon CloudFront、AWS Certificate Manager',
            'inputs' => [
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
            'taskinputs' => [
                'product' => [
                    'name' => '要部署的产品',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'cloudfront', 'label'=>'CloudFront'],
                        ['value'=>'acm', 'label'=>'AWS Certificate Manager'],
                    ],
                    'value' => 'acm',
                    'required' => true,
                ],
                'distribution_id' => [
                    'name' => '分配ID',
                    'type' => 'input',
                    'placeholder' => 'distributions id',
                    'show' => 'product==\'cloudfront\'',
                    'required' => true,
                ],
                'acm_arn' => [
                    'name' => 'ACM ARN',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'acm\'',
                    'note' => '在AWS Certificate Manager控制台查看证书的ARN',
                    'required' => true,
                ],
            ],
        ],
        'gcore' => [
            'name' => 'Gcore',
            'class' => 2,
            'icon' => 'gcore.ico',
            'desc' => '替换Gcore CDN证书',
            'note' => '在 个人资料->API令牌 页面创建API令牌',
            'inputs' => [
                'account' => [
                    'name' => '账户名',
                    'type' => 'input',
                    'placeholder' => '仅用作标记',
                    'required' => true,
                ],
                'apikey' => [
                    'name' => 'API令牌',
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
            'taskinputs' => [
                'id' => [
                    'name' => '证书ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'note' => '在CDN->SSL证书页面查看证书的ID，注意域名是否与证书匹配',
                    'required' => true,
                ],
                'name' => [
                    'name' => '证书名称',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
            ],
        ],
        'cachefly' => [
            'name' => 'Cachefly',
            'class' => 2,
            'icon' => 'cloud.png',
            'desc' => '替换Cachefly CDN证书',
            'note' => '在 API Tokens 页面生成 API Token',
            'inputs' => [
                'account' => [
                    'name' => '账户名',
                    'type' => 'input',
                    'placeholder' => '仅用作标记',
                    'required' => true,
                ],
                'apikey' => [
                    'name' => 'API Token',
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
            'taskinputs' => [

            ],
        ],
        'ssh' => [
            'name' => 'SSH服务器',
            'class' => 3,
            'icon' => 'server.png',
            'desc' => '可通过SSH连接到Linux/Windows服务器并部署证书',
            'note' => '可通过SSH连接到Linux/Windows服务器并部署证书，php需要安装ssh2扩展',
            'tasknote' => '请确保路径存在且有写入权限，路径一定要以/开头（Windows路径请使用/代替\，且需要在最开头加/）',
            'inputs' => [
                'host' => [
                    'name' => '主机地址',
                    'type' => 'input',
                    'placeholder' => '填写IP地址或域名',
                    'required' => true,
                ],
                'port' => [
                    'name' => '端口',
                    'type' => 'input',
                    'placeholder' => '',
                    'value' => '22',
                    'required' => true,
                ],
                'auth' => [
                    'name' => '认证方式',
                    'type' => 'radio',
                    'options' => [
                        '0' => '密码认证',
                        '1' => '密钥认证',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'username' => [
                    'name' => '用户名',
                    'type' => 'input',
                    'placeholder' => '登录用户名',
                    'value' => 'root',
                    'required' => true,
                ],
                'password' => [
                    'name' => '密码',
                    'type' => 'input',
                    'placeholder' => '登录密码',
                    'required' => true,
                    'show' => 'auth==0',
                ],
                'privatekey' => [
                    'name' => '私钥',
                    'type' => 'textarea',
                    'placeholder' => '填写PEM格式私钥内容',
                    'required' => true,
                    'show' => 'auth==1',
                ],
                'windows' => [
                    'name' => '是否Windows',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'note' => 'Windows系统需要先安装OpenSSH',
                    'value' => '0',
                    'required' => true,
                ],
            ],
            'taskinputs' => [
                'format' => [
                    'name' => '证书类型',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'pem', 'label'=>'PEM格式（Nginx/Apache等）'],
                        ['value'=>'pfx', 'label'=>'PFX格式（IIS/Tomcat）'],
                    ],
                    'value' => 'pem',
                    'required' => true,
                ],
                'pem_cert_file' => [
                    'name' => '证书保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/cert.pem',
                    'show' => 'format==\'pem\'',
                    'required' => true,
                ],
                'pem_key_file' => [
                    'name' => '私钥保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/key.pem',
                    'show' => 'format==\'pem\'',
                    'required' => true,
                ],
                'pfx_file' => [
                    'name' => 'PFX证书保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/cert.pfx',
                    'show' => 'format==\'pfx\'',
                    'required' => true,
                ],
                'pfx_pass' => [
                    'name' => 'PFX证书密码',
                    'type' => 'input',
                    'placeholder' => '留空为不设置密码',
                    'show' => 'format==\'pfx\'',
                ],
                'uptype' => [
                    'name' => '上传完操作',
                    'type' => 'radio',
                    'options' => [
                        '0' => '执行指定命令',
                        '1' => '部署到IIS',
                    ],
                    'value' => '0',
                    'show' => 'format==\'pfx\'',
                    'required' => true,
                ],
                'cmd_pre' => [
                    'name' => '上传前执行命令',
                    'type' => 'textarea',
                    'show' => 'format==\'pem\'||uptype==0',
                    'placeholder' => '可留空，上传前执行脚本命令',
                ],
                'cmd' => [
                    'name' => '上传完执行命令',
                    'type' => 'textarea',
                    'show' => 'format==\'pem\'||uptype==0',
                    'placeholder' => '可留空，每行一条命令，如：service nginx reload',
                ],
                'iis_domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '在IIS站点绑定的https域名',
                    'show' => 'format==\'pfx\'&&uptype==1',
                ],
            ],
        ],
        'ftp' => [
            'name' => 'FTP服务器',
            'class' => 3,
            'icon' => 'server.png',
            'desc' => '可将证书上传到FTP服务器',
            'note' => '可将证书上传到FTP服务器，php需要安装ftp扩展',
            'tasknote' => '请确保路径存在且有写入权限',
            'inputs' => [
                'host' => [
                    'name' => 'FTP地址',
                    'type' => 'input',
                    'placeholder' => '填写IP地址或域名',
                    'required' => true,
                ],
                'port' => [
                    'name' => 'FTP端口',
                    'type' => 'input',
                    'placeholder' => '',
                    'value' => '21',
                    'required' => true,
                ],
                'username' => [
                    'name' => '用户名',
                    'type' => 'input',
                    'placeholder' => 'FTP登录用户名',
                    'required' => true,
                ],
                'password' => [
                    'name' => '密码',
                    'type' => 'input',
                    'placeholder' => 'FTP登录密码',
                    'required' => true,
                ],
                'secure' => [
                    'name' => '是否使用SSL',
                    'type' => 'radio',
                    'options' => [
                        '0' => '否',
                        '1' => '是',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
            ],
            'taskinputs' => [
                'format' => [
                    'name' => '证书类型',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'pem', 'label'=>'PEM格式（Nginx/Apache等）'],
                        ['value'=>'pfx', 'label'=>'PFX格式（IIS）'],
                    ],
                    'value' => 'pem',
                    'required' => true,
                ],
                'pem_cert_file' => [
                    'name' => '证书保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/cert.pem',
                    'show' => 'format==\'pem\'',
                    'required' => true,
                ],
                'pem_key_file' => [
                    'name' => '私钥保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/key.pem',
                    'show' => 'format==\'pem\'',
                    'required' => true,
                ],
                'pfx_file' => [
                    'name' => 'PFX证书保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/cert.pfx',
                    'show' => 'format==\'pfx\'',
                    'required' => true,
                ],
                'pfx_pass' => [
                    'name' => 'PFX证书密码',
                    'type' => 'input',
                    'placeholder' => '留空为不设置密码',
                    'show' => 'format==\'pfx\'',
                ],
            ],
        ],
        'local' => [
            'name' => '复制到本机',
            'class' => 3,
            'icon' => 'server2.png',
            'desc' => '将证书复制到本机指定路径',
            'note' => '将证书复制到本机指定路径',
            'tasknote' => '请确保php进程有对证书保存路径的写入权限，宝塔面板需关闭防跨站攻击，如果当前是Docker运行的，则需要做目录映射到宿主机。',
            'inputs' => [],
            'taskinputs' => [
                'format' => [
                    'name' => '证书类型',
                    'type' => 'select',
                    'options' => [
                        ['value'=>'pem', 'label'=>'PEM格式（Nginx/Apache等）'],
                        ['value'=>'pfx', 'label'=>'PFX格式（IIS）'],
                    ],
                    'value' => 'pem',
                    'required' => true,
                ],
                'pem_cert_file' => [
                    'name' => '证书保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/cert.pem',
                    'show' => 'format==\'pem\'',
                    'required' => true,
                ],
                'pem_key_file' => [
                    'name' => '私钥保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/key.pem',
                    'show' => 'format==\'pem\'',
                    'required' => true,
                ],
                'pfx_file' => [
                    'name' => 'PFX证书保存路径',
                    'type' => 'input',
                    'placeholder' => '/path/to/cert.pfx',
                    'show' => 'format==\'pfx\'',
                    'required' => true,
                ],
                'pfx_pass' => [
                    'name' => 'PFX证书密码',
                    'type' => 'input',
                    'placeholder' => '留空为不设置密码',
                    'show' => 'format==\'pfx\'',
                ],
                'cmd' => [
                    'name' => '复制完执行命令',
                    'type' => 'textarea',
                    'placeholder' => '可留空，每行一条命令，如：service nginx reload',
                    'note' => '如需执行命令，php需开放exec函数',
                ],
            ],
        ],
    ];

    public static $class_config = [
        1 => '自建系统',
        2 => '云服务商',
        3 => '服务器',
    ];

    public static function getList()
    {
        return self::$deploy_config;
    }

    private static function getConfig($aid)
    {
        $account = Db::name('cert_account')->where('id', $aid)->find();
        if (!$account) return false;
        return $account;
    }

    public static function getInputs($type, $config = null)
    {
        $config = $config ? json_decode($config, true) : [];
        $inputs = self::$deploy_config[$type]['inputs'];
        foreach ($inputs as &$input) {
            if (isset($config[$input['name']])) {
                $input['value'] = $config[$input['name']];
            }
        }
        return $inputs;
    }

    /**
     * @return DeployInterface|bool
     */
    public static function getModel($aid)
    {
        $account = self::getConfig($aid);
        if (!$account) return false;
        $type = $account['type'];
        $class = "\\app\\lib\\deploy\\{$type}";
        if (class_exists($class)) {
            $config = json_decode($account['config'], true);
            $model = new $class($config);
            return $model;
        }
        return false;
    }

    /**
     * @return DeployInterface|bool
     */
    public static function getModel2($type, $config)
    {
        $class = "\\app\\lib\\deploy\\{$type}";
        if (class_exists($class)) {
            $model = new $class($config);
            return $model;
        }
        return false;
    }
}
