DROP TABLE IF EXISTS `dnsmgr_account`;
CREATE TABLE `dnsmgr_account` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `type` varchar(20) NOT NULL,
  `ak` varchar(256) DEFAULT NULL,
  `sk` varchar(256) DEFAULT NULL,
  `ext` varchar(256) DEFAULT NULL,
  `remark` varchar(100) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_domain`;
CREATE TABLE `dnsmgr_domain` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `aid` int(11) unsigned NOT NULL,
  `name` varchar(128) NOT NULL,
  `thirdid` varchar(60) DEFAULT NULL,
  `addtime` datetime DEFAULT NULL,
  `is_hide` tinyint(1) NOT NULL DEFAULT '0',
  `is_sso` tinyint(1) NOT NULL DEFAULT '0',
  `recordcount` int(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `name` (`name`)
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
  `status` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1000;

DROP TABLE IF EXISTS `dnsmgr_permission`;
CREATE TABLE `dnsmgr_permission` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `uid` int(11) unsigned NOT NULL,
  `domain` varchar(128) NOT NULL,
  `sub` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS `dnsmgr_log`;
CREATE TABLE `dnsmgr_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` int(11) unsigned NOT NULL,
  `action` varchar(40) NOT NULL,
  `domain` varchar(128) NOT NULL DEFAULT '',
  `data` varchar(500) DEFAULT NULL,
  `addtime` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
