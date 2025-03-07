<?php

namespace app\lib;

use think\facade\Db;

class DeployHelper
{
    public static $deploy_config = [
        'btpanel' => [
            'name' => '宝塔面板',
            'class' => 1,
            'icon' => 'bt.ico',
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
                        '0' => '宝塔面板站点的证书',
                        '1' => '宝塔面板本身的证书',
                        '2' => '宝塔邮局域名的证书',
                    ],
                    'value' => '0',
                    'required' => true,
                ],
                'sites' => [
                    'name' => '网站名称列表',
                    'type' => 'textarea',
                    'placeholder' => '填写要部署证书的网站名称，每行一个',
                    'note' => 'PHP项目和反代项目填写创建时绑定的第一个域名，Java/Node/Go等其他项目填写项目名称，邮局填写域名',
                    'show' => 'type==0||type==2',
                    'required' => true,
                ],
            ],
        ],
        'kangle' => [
            'name' => 'Kangle用户',
            'class' => 1,
            'icon' => 'host.png',
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
            'note' => '以上登录地址需填写Easypanel管理员面板地址，非用户面板。',
            'inputs' => [
                'url' => [
                    'name' => '面板地址',
                    'type' => 'input',
                    'placeholder' => 'Easypanel管理员面板地址',
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
        'cdnfly' => [
            'name' => 'Cdnfly',
            'class' => 1,
            'icon' => 'waf.png',
            'note' => '登录Cdnfly控制台->账户中心->API密钥，点击开启后获取',
            'inputs' => [
                'url' => [
                    'name' => '控制台地址',
                    'type' => 'input',
                    'placeholder' => 'Cdnfly控制台地址',
                    'note' => '填写示例：http://demo.cdnfly.cn',
                    'required' => true,
                ],
                'api_key' => [
                    'name' => 'api_key',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
                'api_secret' => [
                    'name' => 'api_secret',
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
                    'note' => '在网站管理->证书管理查看证书的ID，注意域名是否与证书匹配',
                    'required' => true,
                ],
            ],
        ],
        'lecdn' => [
            'name' => 'LeCDN',
            'class' => 1,
            'icon' => 'waf.png',
            'note' => null,
            'inputs' => [
                'url' => [
                    'name' => '控制台地址',
                    'type' => 'input',
                    'placeholder' => 'LeCDN控制台地址',
                    'note' => '填写示例：http://demo.xxxx.cn',
                    'required' => true,
                ],
                'email' => [
                    'name' => '邮箱地址',
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
                    'note' => '在站点->证书管理查看证书的ID，注意域名是否与证书匹配',
                    'required' => true,
                ],
            ],
        ],
        'goedge' => [
            'name' => 'GoEdge',
            'class' => 1,
            'icon' => 'waf.png',
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
        'opanel' => [
            'name' => '1Panel',
            'class' => 1,
            'icon' => 'opanel.png',
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
        'synology' => [
            'name' => '群晖面板',
            'class' => 1,
            'icon' => 'synology.png',
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
        'proxmox' => [
            'name' => 'Proxmox VE',
            'class' => 1,
            'icon' => 'proxmox.ico',
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
            'icon' => 'aliyun.ico',
            'note' => '支持部署到阿里云CDN、ESA、SLB、OSS、WAF等服务',
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
                    'show' => 'product==\'waf\'||product==\'waf2\'||product==\'ddoscoo\'',
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
            'icon' => 'tencent.ico',
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
                    'placeholder' => '',
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
                    'placeholder' => '',
                    'required' => true,
                ],
            ],
        ],
        'doge' => [
            'name' => '多吉云',
            'class' => 2,
            'icon' => 'cloud.png',
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
                    'placeholder' => '',
                    'required' => true,
                ],
            ],
        ],
        'upyun' => [
            'name' => '又拍云',
            'class' => 2,
            'icon' => 'upyun.ico',
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
            'note' => '支持部署到百度云CDN',
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
                'domain' => [
                    'name' => '绑定的域名',
                    'type' => 'input',
                    'placeholder' => '',
                    'required' => true,
                ],
            ],
        ],
        'huoshan' => [
            'name' => '火山引擎',
            'class' => 2,
            'icon' => 'huoshan.ico',
            'note' => '支持部署到火山引擎CDN',
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
                    'show' => 'product!=\'clb\'',
                    'required' => true,
                ],
                'listener_id' => [
                    'name' => '监听器ID',
                    'type' => 'input',
                    'placeholder' => '',
                    'show' => 'product==\'clb\'',
                    'required' => true,
                ],
            ],
        ],
        'west' => [
            'name' => '西部数码',
            'class' => 2,
            'icon' => 'west.ico',
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
        'baishan' => [
            'name' => '白山云',
            'class' => 2,
            'icon' => 'waf.png',
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
            'note' => '支持部署到天翼云CDN',
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
        'allwaf' => [
            'name' => 'AllWAF',
            'class' => 2,
            'icon' => 'waf.png',
            'note' => '在<a href="https://user.allwaf.cn/" target="_blank" rel="noreferrer">ALLWAF</a>访问控制页面创建AccessKey',
            'tasknote' => '系统会根据关联SSL证书的域名，自动更新对应证书',
            'inputs' => [
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
        'aws' => [
            'name' => 'AWS',
            'class' => 2,
            'icon' => 'aws.ico',
            'note' => '支持部署到Amazon CloudFront',
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
                    ],
                    'value' => 'cloudfront',
                    'required' => true,
                ],
                'distribution_id' => [
                    'name' => '分配ID',
                    'type' => 'input',
                    'placeholder' => 'distributions id',
                    'required' => true,
                ],
            ],
        ],
        'gcore' => [
            'name' => 'Gcore',
            'class' => 2,
            'icon' => 'gcore.ico',
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
                    'placeholder' => '填写私钥内容',
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
