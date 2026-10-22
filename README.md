# 彩虹聚合DNS管理系统（优化版）

<div align="center">

[![Last Commit](https://img.shields.io/github/last-commit/allcdn/dnsmgr?style=flat)](https://github.com/allcdn/dnsmgr/commits/main)
[![Issues](https://img.shields.io/github/issues/allcdn/dnsmgr?style=flat)](https://github.com/allcdn/dnsmgr/issues)
[![License](https://img.shields.io/github/license/allcdn/dnsmgr?style=flat)](https://github.com/allcdn/dnsmgr/blob/main/LICENSE)

</div>

本项目是基于 [netcccyun/dnsmgr](https://github.com/netcccyun/dnsmgr) 的增强维护分支，在保留原项目多平台 DNS 管理能力的基础上，重点优化 Cloudflare 自定义主机名、CF 优选 IP 批量任务、智能批量解析和日常运维体验。

> 本仓库为非官方优化分支，与上游项目不存在官方隶属关系。上游原始功能、文档与版权归原作者及相关贡献者所有。

## 功能概览

- 多平台域名解析统一管理
- 多用户与域名权限分配
- API 接口与域名快速登录
- 容灾切换与通知
- 定时解析切换
- Cloudflare 优选 IP 自动更新
- SSL 证书申请与自动部署
- 邮件、微信公众号、Telegram、钉钉、飞书、企业微信等通知渠道

## 优化版新增能力

### Cloudflare 自定义主机名

- 自定义主机名列表支持按创建时间、主机名、源站排序
- 批量 DCV 委派、主机名 TXT 验证、证书 TXT 验证
- 批量操作支持一键选择解析服务商
- 线路支持一级、二级及更深层级的分级级联选择
- 批量线路按服务商过滤，避免混入其他服务商线路
- 同一域名线路请求合并并缓存 24 小时，降低批量加载耗时
- 批量 CF 优选解析支持 `CNAME`、`ALIAS`、`A`、`AAAA`
- 修复特殊记录 ID 复制时报 `querySelector` 非法选择器的问题

### CF 优选 IP 批量任务

- 从 Cloudflare 自定义主机名批量拉取主机名
- 自动匹配本地非 Cloudflare DNS 域名
- 同一主机名存在多个解析服务商时，支持逐行选择或一键选择
- 默认只显示“可创建”的主机名，已存在任务可通过“显示全部”查看
- 支持批量创建和更新现有优选任务
- 任务管理支持批量修改线路类型、CDN、IP 类型、解析数量、TTL、备注和启用状态
- 千级任务列表查询优化
- 定时执行复用域名与 DNS 驱动实例
- 自动清理同一线路的重复解析记录，并严格限制为设定数量

### 智能批量添加解析

- 未选择默认域名时，可自动加载单域名批次的线路
- 兼容一级线路 `parent` 为空字符串的 DNS 驱动
- 线路按层级级联显示
- 支持按 DNS 提供商能力显示 `ALIAS` 记录

### ALIAS 记录

当前内置驱动的 ALIAS 支持情况：

| DNS 服务商 | 支持情况 | 说明 |
| --- | --- | --- |
| 阿里云 | 支持 | 仅云解析 Ultimate / Premium 版本 |
| HE DNS | 支持 | 原生 ALIAS |
| PowerDNS | 支持 | 需要服务端启用 ALIAS 解析 |
| Spaceship | 支持 | 原生 ALIAS |
| 其他内置服务商 | 暂未接入 | 以服务商官方能力为准 |

## 支持的部分 DNS 平台

阿里云、腾讯云、华为云、百度云、西部数码、火山引擎、DNSLA、Cloudflare、AWS Route 53、NameSilo、HE DNS、PowerDNS、Spaceship、Technitium、青云、京东云、宝塔 DNS、GoEdge、dynv6 等。

具体可用功能取决于对应服务商 API、账户版本和驱动实现。

## 环境要求

- PHP `8.2+`，生产环境已验证 PHP `8.5`
- MySQL `5.6+`
- 必需的 PHP 扩展：
  - `curl`
  - `fileinfo`
  - `ftp`
  - `gd`
  - `mbstring`
  - `openssl`
  - `pdo`
  - `pdo_mysql`
  - `sockets`
  - `zip`
  - `ssh2`

## 源码部署

```bash
git clone https://github.com/allcdn/dnsmgr.git
cd dnsmgr
composer install --no-dev
```

网站根目录必须指向：

```text
public
```

首次访问时，根据安装页面填写数据库和站点信息。安装程序会生成 `.env` 文件。

建议为以下目录设置 Web 运行用户写权限：

```bash
chown -R www:www runtime
```

### Nginx 伪静态

```nginx
location ~* (runtime|app|config|vendor)/ {
    return 403;
}

location / {
    if (!-e $request_filename) {
        rewrite ^(.*)$ /index.php?s=$1 last;
    }
}
```

### Apache 伪静态

```apache
<IfModule mod_rewrite.c>
    Options +FollowSymlinks -Multiviews
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.*)$ index.php/$1 [QSA,PT,L]
</IfModule>
```

## 更新方式

生产环境使用 Git 拉取更新：

```bash
cd /path/to/dnsmgr
git pull --ff-only origin main

# composer.lock 有变化时执行
composer install --no-dev

# 清理运行时缓存
rm -rf runtime/cache/* runtime/temp/*
```

然后按实际环境重启 PHP-FPM，并强制刷新浏览器。

### 同步官方更新

本仓库将官方仓库配置为 `upstream`：

```bash
git fetch upstream
git merge upstream/main
```

解决冲突并完成测试后，再推送到本仓库：

```bash
git push origin main
```

## 生产环境注意事项

- **不要使用后台“在线更新”功能。** 在线更新会直接覆盖 `app/` 等目录，可能覆盖本优化分支的定制代码。
- 更新前备份数据库、`.env` 和持久化文件。
- 不要执行 `git clean -fdx`，否则可能删除 `.env`、`vendor`、`runtime/storage` 等未跟踪文件。
- 不要强制推送或使用 `git reset --hard` 覆盖生产修改。
- 修改代码后先提交，再执行 `git pull --ff-only`。
- 容器部署时，宝塔面板切换 PHP 版本不会影响容器内部 PHP。

## 目录结构

```text
app/
  controller/       控制器
  service/          业务服务
  lib/dns/          DNS 驱动
  view/             后台页面模板
config/             应用配置
public/             网站运行目录
route/              路由定义
runtime/            运行时缓存与日志
```

## 问题反馈

- 优化功能与当前分支问题：[allcdn/dnsmgr Issues](https://github.com/allcdn/dnsmgr/issues)
- 上游项目：[netcccyun/dnsmgr](https://github.com/netcccyun/dnsmgr)

## 致谢

感谢 [netcccyun/dnsmgr](https://github.com/netcccyun/dnsmgr) 原作者及所有贡献者。本优化版仅在其基础上维护与扩展，不替代上游官方版本。

## 许可证

本项目遵循仓库中的 [LICENSE](LICENSE) 文件。
