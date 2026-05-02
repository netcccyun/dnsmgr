DROP TABLE IF EXISTS dnsmgr_config;
CREATE TABLE dnsmgr_config (
  "key" varchar(32) NOT NULL,
  value text DEFAULT NULL,
  PRIMARY KEY ("key")
);

INSERT INTO dnsmgr_config ("key", value) VALUES ('version', '1048');
INSERT INTO dnsmgr_config ("key", value) VALUES ('notice_mail', '0');
INSERT INTO dnsmgr_config ("key", value) VALUES ('notice_wxtpl', '0');
INSERT INTO dnsmgr_config ("key", value) VALUES ('mail_smtp', 'smtp.qq.com');
INSERT INTO dnsmgr_config ("key", value) VALUES ('mail_port', '465');

DROP TABLE IF EXISTS dnsmgr_account;
CREATE TABLE dnsmgr_account (
  id serial PRIMARY KEY,
  type varchar(20) NOT NULL,
  name varchar(255) NOT NULL,
  config text DEFAULT NULL,
  remark varchar(100) DEFAULT NULL,
  addtime timestamp DEFAULT NULL
);

DROP TABLE IF EXISTS dnsmgr_domain;
CREATE TABLE dnsmgr_domain (
  id serial PRIMARY KEY,
  aid integer NOT NULL,
  name varchar(255) NOT NULL,
  thirdid varchar(60) DEFAULT NULL,
  addtime timestamp DEFAULT NULL,
  is_hide smallint NOT NULL DEFAULT 0,
  is_sso smallint NOT NULL DEFAULT 0,
  recordcount integer NOT NULL DEFAULT 0,
  remark varchar(100) DEFAULT NULL,
  is_notice smallint NOT NULL DEFAULT 0,
  regtime timestamp DEFAULT NULL,
  expiretime timestamp DEFAULT NULL,
  checktime timestamp DEFAULT NULL,
  noticetime timestamp DEFAULT NULL,
  checkstatus smallint NOT NULL DEFAULT 0
);
CREATE INDEX dnsmgr_domain_name_idx ON dnsmgr_domain (name);

DROP TABLE IF EXISTS dnsmgr_user;
CREATE TABLE dnsmgr_user (
  id integer NOT NULL,
  username varchar(64) NOT NULL,
  password varchar(80) NOT NULL,
  is_api smallint NOT NULL DEFAULT 0,
  apikey varchar(32) DEFAULT NULL,
  level integer NOT NULL DEFAULT 0,
  regtime timestamp DEFAULT NULL,
  lasttime timestamp DEFAULT NULL,
  totp_open smallint NOT NULL DEFAULT 0,
  totp_secret varchar(100) DEFAULT NULL,
  status smallint NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
);
CREATE SEQUENCE dnsmgr_user_id_seq START 1000 OWNED BY dnsmgr_user.id;
ALTER TABLE dnsmgr_user ALTER COLUMN id SET DEFAULT nextval('dnsmgr_user_id_seq');
CREATE INDEX dnsmgr_user_username_idx ON dnsmgr_user (username);

DROP TABLE IF EXISTS dnsmgr_permission;
CREATE TABLE dnsmgr_permission (
  id serial PRIMARY KEY,
  uid integer NOT NULL,
  domain varchar(255) NOT NULL,
  sub varchar(80) DEFAULT NULL
);
CREATE INDEX dnsmgr_permission_uid_idx ON dnsmgr_permission (uid);

DROP TABLE IF EXISTS dnsmgr_log;
CREATE TABLE dnsmgr_log (
  id serial PRIMARY KEY,
  uid integer NOT NULL,
  action varchar(40) NOT NULL,
  domain varchar(255) NOT NULL DEFAULT '',
  data varchar(500) DEFAULT NULL,
  addtime timestamp NOT NULL
);
CREATE INDEX dnsmgr_log_uid_idx ON dnsmgr_log (uid);
CREATE INDEX dnsmgr_log_domain_idx ON dnsmgr_log (domain);

DROP TABLE IF EXISTS dnsmgr_dmtask;
CREATE TABLE dnsmgr_dmtask (
  id serial PRIMARY KEY,
  did integer NOT NULL,
  rr varchar(128) NOT NULL,
  recordid varchar(60) NOT NULL,
  type smallint NOT NULL DEFAULT 0,
  main_value varchar(128) DEFAULT NULL,
  backup_value varchar(128) DEFAULT NULL,
  checktype smallint NOT NULL DEFAULT 0,
  checkurl varchar(512) DEFAULT NULL,
  tcpport integer DEFAULT NULL,
  frequency smallint NOT NULL,
  cycle smallint NOT NULL DEFAULT 3,
  timeout smallint NOT NULL DEFAULT 2,
  remark varchar(100) DEFAULT NULL,
  proxy smallint NOT NULL DEFAULT 0,
  cdn smallint NOT NULL DEFAULT 0,
  addtime integer NOT NULL DEFAULT 0,
  checktime integer NOT NULL DEFAULT 0,
  checknexttime integer NOT NULL DEFAULT 0,
  switchtime integer NOT NULL DEFAULT 0,
  errcount smallint NOT NULL DEFAULT 0,
  status smallint NOT NULL DEFAULT 0,
  active smallint NOT NULL DEFAULT 0,
  recordinfo varchar(200) DEFAULT NULL
);
CREATE INDEX dnsmgr_dmtask_did_idx ON dnsmgr_dmtask (did);

DROP TABLE IF EXISTS dnsmgr_dmlog;
CREATE TABLE dnsmgr_dmlog (
  id serial PRIMARY KEY,
  taskid integer NOT NULL,
  action smallint NOT NULL DEFAULT 0,
  errmsg varchar(100) DEFAULT NULL,
  date timestamp DEFAULT NULL
);
CREATE INDEX dnsmgr_dmlog_taskid_idx ON dnsmgr_dmlog (taskid);
CREATE INDEX dnsmgr_dmlog_date_idx ON dnsmgr_dmlog (date);

DROP TABLE IF EXISTS dnsmgr_optimizeip;
CREATE TABLE dnsmgr_optimizeip (
  id serial PRIMARY KEY,
  did integer NOT NULL,
  rr varchar(128) NOT NULL,
  type smallint NOT NULL DEFAULT 0,
  ip_type varchar(10) NOT NULL,
  cdn_type smallint NOT NULL DEFAULT 1,
  recordnum smallint NOT NULL DEFAULT 2,
  ttl integer NOT NULL DEFAULT 600,
  remark varchar(100) DEFAULT NULL,
  addtime timestamp NOT NULL,
  updatetime timestamp DEFAULT NULL,
  status smallint NOT NULL DEFAULT 0,
  active smallint NOT NULL DEFAULT 0,
  errmsg varchar(100) DEFAULT NULL
);
CREATE INDEX dnsmgr_optimizeip_did_idx ON dnsmgr_optimizeip (did);

DROP TABLE IF EXISTS dnsmgr_cert_account;
CREATE TABLE dnsmgr_cert_account (
  id serial PRIMARY KEY,
  type varchar(20) NOT NULL,
  name varchar(255) NOT NULL,
  config text DEFAULT NULL,
  ext text DEFAULT NULL,
  remark varchar(100) DEFAULT NULL,
  deploy smallint NOT NULL DEFAULT 0,
  addtime timestamp DEFAULT NULL
);

DROP TABLE IF EXISTS dnsmgr_cert_order;
CREATE TABLE dnsmgr_cert_order (
  id serial PRIMARY KEY,
  aid integer NOT NULL,
  keytype varchar(20) DEFAULT NULL,
  keysize varchar(20) DEFAULT NULL,
  addtime timestamp DEFAULT NULL,
  updatetime timestamp DEFAULT NULL,
  processid varchar(32) DEFAULT NULL,
  issuetime timestamp DEFAULT NULL,
  expiretime timestamp DEFAULT NULL,
  issuer varchar(100) DEFAULT NULL,
  status smallint NOT NULL DEFAULT 0,
  error varchar(300) DEFAULT NULL,
  isauto smallint NOT NULL DEFAULT 0,
  retry smallint NOT NULL DEFAULT 0,
  retry2 smallint NOT NULL DEFAULT 0,
  retrytime timestamp DEFAULT NULL,
  islock smallint NOT NULL DEFAULT 0,
  locktime timestamp DEFAULT NULL,
  issend smallint NOT NULL DEFAULT 0,
  info text DEFAULT NULL,
  dns text DEFAULT NULL,
  fullchain text DEFAULT NULL,
  privatekey text DEFAULT NULL
);

DROP TABLE IF EXISTS dnsmgr_cert_domain;
CREATE TABLE dnsmgr_cert_domain (
  id serial PRIMARY KEY,
  oid integer NOT NULL,
  domain varchar(255) NOT NULL,
  sort integer NOT NULL DEFAULT 0
);
CREATE INDEX dnsmgr_cert_domain_oid_idx ON dnsmgr_cert_domain (oid);

DROP TABLE IF EXISTS dnsmgr_cert_deploy;
CREATE TABLE dnsmgr_cert_deploy (
  id serial PRIMARY KEY,
  aid integer NOT NULL,
  oid integer NOT NULL,
  issuetime timestamp DEFAULT NULL,
  config text DEFAULT NULL,
  remark varchar(100) DEFAULT NULL,
  addtime timestamp DEFAULT NULL,
  lasttime timestamp DEFAULT NULL,
  processid varchar(32) DEFAULT NULL,
  status smallint NOT NULL DEFAULT 0,
  error varchar(300) DEFAULT NULL,
  active smallint NOT NULL DEFAULT 0,
  retry smallint NOT NULL DEFAULT 0,
  retrytime timestamp DEFAULT NULL,
  islock smallint NOT NULL DEFAULT 0,
  locktime timestamp DEFAULT NULL,
  issend smallint NOT NULL DEFAULT 0,
  info text DEFAULT NULL
);

DROP TABLE IF EXISTS dnsmgr_cert_cname;
CREATE TABLE dnsmgr_cert_cname (
  id serial PRIMARY KEY,
  domain varchar(255) NOT NULL,
  did integer NOT NULL,
  rr varchar(128) NOT NULL,
  addtime timestamp DEFAULT NULL,
  status smallint NOT NULL DEFAULT 0
);

DROP TABLE IF EXISTS dnsmgr_sctask;
CREATE TABLE dnsmgr_sctask (
  id serial PRIMARY KEY,
  did integer NOT NULL,
  rr varchar(128) NOT NULL,
  recordid varchar(60) NOT NULL,
  type smallint NOT NULL DEFAULT 0,
  cycle smallint NOT NULL DEFAULT 0,
  switchtype smallint NOT NULL DEFAULT 0,
  switchdate varchar(10) DEFAULT NULL,
  switchtime varchar(20) DEFAULT NULL,
  value varchar(128) DEFAULT NULL,
  line varchar(20) DEFAULT NULL,
  addtime integer NOT NULL DEFAULT 0,
  updatetime integer NOT NULL DEFAULT 0,
  nexttime integer NOT NULL DEFAULT 0,
  active smallint NOT NULL DEFAULT 0,
  recordinfo varchar(200) DEFAULT NULL,
  remark varchar(100) DEFAULT NULL
);
CREATE INDEX dnsmgr_sctask_did_idx ON dnsmgr_sctask (did);

DROP TABLE IF EXISTS dnsmgr_domain_alias;
CREATE TABLE dnsmgr_domain_alias (
  id serial PRIMARY KEY,
  did integer NOT NULL,
  name varchar(255) NOT NULL
);
CREATE INDEX dnsmgr_domain_alias_did_idx ON dnsmgr_domain_alias (did);
CREATE INDEX dnsmgr_domain_alias_name_idx ON dnsmgr_domain_alias (name);
