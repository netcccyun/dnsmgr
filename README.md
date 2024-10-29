## 聚合DNS管理系统

聚合DNS管理系统可以实现在一个网站内管理多个平台的域名解析，目前已支持的域名平台有：

- 阿里云
- 腾讯云
- 华为云
- 西部数码
- DNSLA
- CloudFlare

### 功能特性

- 多用户管理，可为每个用户可分配不同的域名解析权限
- 提供API接口，可获取域名单独的登录链接，方便各种IDC系统对接
- 容灾切换功能，支持ping、tcp、http(s)检测协议并自动暂停/修改域名解析，并支持邮件、微信公众号通知
- CF优选IP功能，支持获取最新的Cloudflare优选IP，并自动更新到解析记录

### 演示截图

添加域名账户

![](https://p0.meituan.net/csc/090508cdc7aaabd185ba9c76a8c099f9283946.png)

域名管理列表

![](https://p0.meituan.net/csc/60bf3f607d40f30f152ad1f6ee3be098357839.png)

域名DNS解析管理，支持解析批量操作

![](https://p0.meituan.net/csc/f99c599d4febced404c88672dd50d62c212895.png)

用户管理添加用户，支持为用户开启API接口

![](https://p0.meituan.net/csc/d1bd90bedca9b6cbc5da40286bdb5cd5228438.png)

CF优选IP功能，添加优选IP任务

![](https://p1.meituan.net/csc/da70c76753aee4bce044d16fadd56e5f217660.png)

### 部署方法

* 从[GitHub](https://github.com/coolxitech/dnsmgr)页面下载源码

* 运行环境要求PHP8.0+，MySQL5.6+

* 设置网站运行目录为`public`

* 设置伪静态为`ThinkPHP`

* 如果是下载的Source code包，还需Composer安装依赖（Release页面下载的安装包不需要）

  ```
  composer install --no-dev
  ```

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

### Docker部署方法
非原仓镜像，仅运行网站服务，数据库服务需要自行创建。

容器使用本地数据库需要获取Docker网络主机IP地址且不能使用`127.0.0.1`和`localhost`，一般默认是`172.17.0.1`。

首先需要安装Docker，然后执行以下命令拉取镜像并启动（启动后监听8000端口）：

```
docker run --name dnsmgr -dit -p 8000:8000 -v /var/dnsmgr:/app kpxyyyy/dnsmgr
```
推荐使用Nginx进行反向代理.
### 版权信息

版权所有Copyright © 2023~2024 by 消失的彩虹海(https://blog.cccyun.cn)

