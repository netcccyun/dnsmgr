CREATE TABLE IF NOT EXISTS `dnsmgr_config` (
  `key` varchar(32) NOT NULL,
  `value` TEXT DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `dnsmgr_dmtask` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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

CREATE TABLE IF NOT EXISTS `dnsmgr_dmlog` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `taskid` int(11) unsigned NOT NULL,
  `action` tinyint(4) NOT NULL DEFAULT 0,
  `errmsg` varchar(100) DEFAULT NULL,
  `date` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `taskid` (`taskid`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;