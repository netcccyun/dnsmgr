## 聚合DNS管理系统

聚合DNS管理系统可以实现在一个网站内管理多个平台的域名解析，目前已支持的域名平台有：阿里云、腾讯云、华为云、百度云、西部数码、火山引擎、DNSLA、CloudFlare、Namesilo

### 功能特性

- 多用户管理，可为每个用户可分配不同的域名解析权限
- 提供API接口，可获取域名单独的登录链接，方便各种IDC系统对接
- 容灾切换功能，支持ping、tcp、http(s)检测协议并自动暂停/修改域名解析，并支持邮件、微信公众号、TG群机器人通知
- CF优选IP功能，支持获取最新的Cloudflare优选IP，并自动更新到解析记录
- SSL证书申请与自动部署功能，支持从Let's Encrypt等渠道申请SSL证书，并自动部署到各种面板、云服务商、服务器等

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

SSL证书申请功能

![](https://blog.cccyun.cn/content/uploadfile/202412/QQ%E6%88%AA%E5%9B%BE20241221154857.png)

![](https://blog.cccyun.cn/content/uploadfile/202412/QQ%E6%88%AA%E5%9B%BE20241221154652.png?a)

SSL证书自动部署功能

![](https://blog.cccyun.cn/content/uploadfile/202412/QQ%E6%88%AA%E5%9B%BE20241221154702.png)

![](https://blog.cccyun.cn/content/uploadfile/202412/QQ%E6%88%AA%E5%9B%BE20241221154804.png)

### 部署方法

* 从[Release](https://github.com/netcccyun/dnsmgr/releases)页面下载安装包

* 运行环境要求PHP7.4+，MySQL5.6+

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

首先需要安装Docker，然后执行以下命令拉取镜像并启动（启动后监听8081端口）：

```
docker run --name dnsmgr -dit -p 8081:80 -v /var/dnsmgr:/app/www netcccyun/dnsmgr
```

访问并安装好后如果容灾切换未自动启动，重启容器即可：

```
docker restart dnsmgr
```

### Docker-compose部署方法

创建`my.cnf`数据库配置并写入一下内容：
```
[mysqld]
sql_mode=STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION
```

`docker-compose.yml`配置
```
services:
  dnsmgr:
    image: netcccyun/dnsmgr
    container_name: dnsmgr
    ports:
      - 8081:80
    volumes:
      - ./dnsmgr/web:/app/www
    depends_on:
      - dnsmgr-mysql

  dnsmgr-mysql:
    image: mysql:5.7
    restart: always
    volumes:
      - ./my.cnf:/etc/mysql/my.cnf  # 数据库配置
      - ./mysql:/var/lib/mysql      # 数据库持久化存储
    environment:
      - MYSQL_DATABASE=dnsmgr       		# 数据库名称
      - MYSQL_USER=dnsmgr           		# 数据库用户
      - MYSQL_PASSWORD=dnsmgr123456		# 数据库密码
      - MYSQL_ROOT_PASSWORD=dnsmgr123456	# 数据库root密码
      - TZ=Asia/Shanghai
```

启动：`docker compose up -d`

面板端口：`8081`

面板初始化界面数据库地址填写：`dnsmgr-mysql`


### 作者信息

消失的彩虹海(https://blog.cccyun.cn)

### 其他推荐

- [彩虹云主机 - 免备案CDN/虚拟主机](https://www.cccyun.net/)
- [小白云高防云服务器](https://www.xiaobaiyun.cn/aff/GMLPMFOV)

