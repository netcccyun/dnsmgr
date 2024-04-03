## 聚合DNS管理系统

聚合DNS管理系统可以实现在一个网站内管理多个平台的域名解析，目前已支持的域名平台有：

- 阿里云
- 腾讯云
- 华为云
- 西部数码
- CloudFlare

本系统支持多用户，每个用户可分配不同的域名解析权限；支持API接口，支持获取域名独立DNS控制面板登录链接，方便各种IDC系统对接。

### 演示截图

添加域名账户

![](https://p0.meituan.net/csc/090508cdc7aaabd185ba9c76a8c099f9283946.png)

域名管理列表

![](https://p0.meituan.net/csc/60bf3f607d40f30f152ad1f6ee3be098357839.png)

域名DNS解析管理，支持解析批量操作

![](https://p0.meituan.net/csc/f99c599d4febced404c88672dd50d62c212895.png)

用户管理添加用户，支持为用户开启API接口

![](https://p0.meituan.net/csc/d1bd90bedca9b6cbc5da40286bdb5cd5228438.png)

### 部署方法

* 运行环境要求PHP7.4+，MySQL5.6+
* 设置网站运行目录为`public`
* 设置伪静态为`ThinkPHP`
* 访问网站，会自动跳转到安装页面，根据提示安装完成
* 访问首页登录控制面板

##### 伪静态规则

* Nginx

```
location / {
	if (!-e $request_filename){
		rewrite  ^(.*)$  /index.php?s=$1  last;   break;
	}
}
```

* Apache

```
<IfModule mod_rewrite.c>
  Options +FollowSymlinks -Multiviews
  RewriteEngine On

  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^(.*)$ index.php/$1 [QSA,PT,L]
</IfModule>
```

### 版权信息

版权所有Copyright © 2023~2024 by 消失的彩虹海(https://blog.cccyun.cn)

### 其他推荐

- [彩虹云主机 - 免备案CDN/虚拟主机](https://www.cccyun.net/)
- [小白云高防云服务器](https://www.xiaobaiyun.cn/aff/GMLPMFOV)

