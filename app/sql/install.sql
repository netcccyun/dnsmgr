DROP TABLE IF EXISTS `dnsmgr_config`;
CREATE TABLE `dnsmgr_config` (
  `key` varchar(32) NOT NULL,
  `value` TEXT DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `dnsmgr_config` VALUES ('version', '1050');
INSERT INTO `dnsmgr_config` VALUES ('notice_mail', '0');
INSERT INTO `dnsmgr_config` VALUES ('notice_wxtpl', '0');
INSERT INTO `dnsmgr_config` VALUES ('mail_smtp', 'smtp.qq.com');
INSERT INTO `dnsmgr_config` VALUES ('mail_port', '465');

DROP TABLE IF EXISTS `dnsmgr_account`;
CREATE TABLE `dnsmgr_account` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `type` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `config` text DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_domain`;
CREATE TABLE `dnsmgr_domain` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `aid` int(11) unsigned NOT NULL,
  `cid` int(11) unsigned NOT NULL DEFAULT '0',
  `name` varchar(255) NOT NULL,
  `thirdid` varchar(60) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `is_hide` tinyint(1) NOT NULL DEFAULT '0',
  `is_sso` tinyint(1) NOT NULL DEFAULT '0',
  `recordcount` int(1) NOT NULL DEFAULT '0',
  `remark` varchar(100) DEFAULT NULL,
  `is_notice` tinyint(1) NOT NULL DEFAULT '0',
  `regtime` datetime DEFAULT NULL,
  `expiretime` datetime DEFAULT NULL,
  `checktime` datetime DEFAULT NULL,
  `noticetime` datetime DEFAULT NULL,
  `checkstatus` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `name` (`name`),
  KEY `cid` (`cid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_user`;
CREATE TABLE `dnsmgr_user` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `username` varchar(64) NOT NULL,
  `password` varchar(80) NOT NULL,
  `is_api` tinyint(1) NOT NULL DEFAULT '0',
  `apikey` varchar(32) DEFAULT NULL,
  `level` int(11) NOT NULL DEFAULT '0',
  `regtime` datetime DEFAULT NULL,
  `lasttime` datetime DEFAULT NULL,
  `totp_open` tinyint(1) NOT NULL DEFAULT '0',
  `totp_secret` varchar(100) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000;

DROP TABLE IF EXISTS `dnsmgr_permission`;
CREATE TABLE `dnsmgr_permission` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `uid` int(11) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `sub` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_log`;
CREATE TABLE `dnsmgr_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL,
  `action` varchar(40) NOT NULL,
  `domain` varchar(255) NOT NULL DEFAULT '',
  `data` varchar(500) DEFAULT NULL,
  `addtime` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_dmtask`;
CREATE TABLE `dnsmgr_dmtask` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `did` int(11) unsigned NOT NULL,
  `rr` varchar(128) NOT NULL,
  `recordid` varchar(60) NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  `main_value` varchar(128) DEFAULT NULL,
  `backup_value` varchar(128) DEFAULT NULL,
  `checktype` tinyint(1) NOT NULL DEFAULT 0,
  `checkurl` varchar(512) DEFAULT NULL,
  `tcpport` int(5) DEFAULT NULL,
  `frequency` tinyint(5) NOT NULL,
  `cycle` tinyint(5) NOT NULL DEFAULT 3,
  `timeout` tinyint(5) NOT NULL DEFAULT 2,
  `remark` varchar(100) DEFAULT NULL,
  `proxy` tinyint(1) NOT NULL DEFAULT 0,
  `cdn` tinyint(1) NOT NULL DEFAULT 0,
  `addtime` int(11) NOT NULL DEFAULT 0,
  `checktime` int(11) NOT NULL DEFAULT 0,
  `checknexttime` int(11) NOT NULL DEFAULT 0,
  `switchtime` int(11) NOT NULL DEFAULT 0,
  `errcount` tinyint(5) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `recordinfo` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `did` (`did`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_dmlog`;
CREATE TABLE `dnsmgr_dmlog` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `taskid` int(11) unsigned NOT NULL,
  `action` tinyint(4) NOT NULL DEFAULT 0,
  `errmsg` varchar(100) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `taskid` (`taskid`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_optimizeip`;
CREATE TABLE `dnsmgr_optimizeip` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `did` int(11) unsigned NOT NULL,
  `rr` varchar(128) NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  `ip_type` varchar(10) NOT NULL,
  `cdn_type` tinyint(5) NOT NULL DEFAULT 1,
  `recordnum` tinyint(5) NOT NULL DEFAULT 2,
  `ttl` int(5) NOT NULL DEFAULT 600,
  `remark` varchar(100) DEFAULT NULL,
  `addtime` datetime NOT NULL,
  `updatetime` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `errmsg` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `did` (`did`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_cert_account`;
CREATE TABLE `dnsmgr_cert_account` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `type` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `config` text DEFAULT NULL,
  `ext` text DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `deploy` tinyint(1) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_cert_order`;
CREATE TABLE `dnsmgr_cert_order` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `aid` int(11) unsigned NOT NULL,
  `keytype` varchar(20) DEFAULT NULL,
  `keysize` varchar(20) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `updatetime` datetime DEFAULT NULL,
  `processid` varchar(32) DEFAULT NULL,
  `issuetime` datetime DEFAULT NULL,
  `expiretime` datetime DEFAULT NULL,
  `issuer` varchar(100) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `error` varchar(300) DEFAULT NULL,
  `isauto` tinyint(1) NOT NULL DEFAULT '0',
  `retry` tinyint(4) NOT NULL DEFAULT '0',
  `retry2` tinyint(4) NOT NULL DEFAULT '0',
  `retrytime` datetime DEFAULT NULL,
  `islock` tinyint(1) NOT NULL DEFAULT '0',
  `locktime` datetime DEFAULT NULL,
  `issend` tinyint(1) NOT NULL DEFAULT '0',
  `info` text DEFAULT NULL,
  `dns` text DEFAULT NULL,
  `fullchain` text DEFAULT NULL,
  `privatekey` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_cert_domain`;
CREATE TABLE `dnsmgr_cert_domain` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `oid` int(11) unsigned NOT NULL,
  `domain` varchar(255) NOT NULL,
  `sort` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `oid` (`oid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_cert_deploy`;
CREATE TABLE `dnsmgr_cert_deploy` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `aid` int(11) unsigned NOT NULL,
  `oid` int(11) unsigned NOT NULL,
  `issuetime` datetime DEFAULT NULL,
  `config` text DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `lasttime` datetime DEFAULT NULL,
  `processid` varchar(32) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `error` varchar(300) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `retry` tinyint(4) NOT NULL DEFAULT '0',
  `retrytime` datetime DEFAULT NULL,
  `islock` tinyint(1) NOT NULL DEFAULT '0',
  `locktime` datetime DEFAULT NULL,
  `issend` tinyint(1) NOT NULL DEFAULT '0',
  `info` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_cert_cname`;
CREATE TABLE `dnsmgr_cert_cname` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `domain` varchar(255) NOT NULL,
  `did` int(11) unsigned NOT NULL,
  `rr` varchar(128) NOT NULL,
  `addtime` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_sctask`;
CREATE TABLE `dnsmgr_sctask` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `did` int(11) unsigned NOT NULL,
  `rr` varchar(128) NOT NULL,
  `recordid` varchar(60) NOT NULL,
  `type` tinyint(1) NOT NULL DEFAULT 0,
  `cycle` tinyint(1) NOT NULL DEFAULT 0,
  `switchtype` tinyint(1) NOT NULL DEFAULT 0,
  `switchdate` varchar(10) DEFAULT NULL,
  `switchtime` varchar(20) DEFAULT NULL,
  `value` varchar(128) DEFAULT NULL,
  `line` varchar(20) DEFAULT NULL,
  `addtime` int(11) NOT NULL DEFAULT 0,
  `updatetime` int(11) NOT NULL DEFAULT 0,
  `nexttime` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `recordinfo` varchar(200) DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `did` (`did`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_oauth_provider`;
CREATE TABLE `dnsmgr_oauth_provider` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `name` varchar(64) NOT NULL COMMENT '提供商名称',
  `type` varchar(32) NOT NULL COMMENT '类型: qq/github/oauth2/oidc/cccyun',
  `logo` varchar(255) DEFAULT NULL COMMENT 'Logo: FA图标类名或URL',
  `client_id` varchar(255) NOT NULL,
  `client_secret` varchar(255) NOT NULL,
  `oauth_authorize_url` varchar(1024) DEFAULT NULL COMMENT '自定义OAuth2授权端点',
  `oauth_token_url` varchar(1024) DEFAULT NULL COMMENT '自定义OAuth2 Token端点',
  `oauth_userinfo_url` varchar(1024) DEFAULT NULL COMMENT '自定义OAuth2用户信息端点',
  `oidc_issuer` varchar(1024) DEFAULT NULL COMMENT 'OIDC发行者URL',
  `scopes` varchar(1024) DEFAULT NULL COMMENT '请求的scope',
  `userinfo_fields` text DEFAULT NULL COMMENT '用户信息字段映射JSON',
  `ext_config` text DEFAULT NULL COMMENT '扩展配置JSON',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sort` int(11) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_enabled_sort` (`enabled`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_user_oauth`;
CREATE TABLE `dnsmgr_user_oauth` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `user_id` int(11) unsigned NOT NULL,
  `provider_id` int(11) unsigned NOT NULL,
  `openid` varchar(190) NOT NULL COMMENT 'OAuth用户唯一标识',
  `nickname` varchar(128) DEFAULT NULL,
  `email` varchar(128) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `access_token` text DEFAULT NULL COMMENT '加密存储',
  `refresh_token` text DEFAULT NULL COMMENT '加密存储',
  `token_expires` datetime DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `lasttime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_openid` (`provider_id`, `openid`),
  UNIQUE KEY `uk_user_provider` (`user_id`, `provider_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `dnsmgr_config` (`key`, `value`) VALUES ('oauth_disable_password', '0');

DROP TABLE IF EXISTS `dnsmgr_domain_alias`;
CREATE TABLE `dnsmgr_domain_alias` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `did` int(11) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `did` (`did`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_domain_category`;
CREATE TABLE `dnsmgr_domain_category` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `name` varchar(50) NOT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `sort` int(11) NOT NULL DEFAULT '0',
  `addtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
