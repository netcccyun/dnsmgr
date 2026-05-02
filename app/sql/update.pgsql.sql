CREATE TABLE IF NOT EXISTS dnsmgr_config (
  "key" varchar(32) NOT NULL,
  value text DEFAULT NULL,
  PRIMARY KEY ("key")
);

CREATE TABLE IF NOT EXISTS dnsmgr_dmtask (
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
  addtime integer NOT NULL DEFAULT 0,
  checktime integer NOT NULL DEFAULT 0,
  checknexttime integer NOT NULL DEFAULT 0,
  switchtime integer NOT NULL DEFAULT 0,
  errcount smallint NOT NULL DEFAULT 0,
  status smallint NOT NULL DEFAULT 0,
  active smallint NOT NULL DEFAULT 0,
  recordinfo varchar(200) DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS dnsmgr_dmtask_did_idx ON dnsmgr_dmtask (did);

CREATE TABLE IF NOT EXISTS dnsmgr_dmlog (
  id serial PRIMARY KEY,
  taskid integer NOT NULL,
  action smallint NOT NULL DEFAULT 0,
  errmsg varchar(100) DEFAULT NULL,
  date timestamp DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS dnsmgr_dmlog_taskid_idx ON dnsmgr_dmlog (taskid);
CREATE INDEX IF NOT EXISTS dnsmgr_dmlog_date_idx ON dnsmgr_dmlog (date);

CREATE TABLE IF NOT EXISTS dnsmgr_optimizeip (
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
CREATE INDEX IF NOT EXISTS dnsmgr_optimizeip_did_idx ON dnsmgr_optimizeip (did);

ALTER TABLE dnsmgr_domain ADD COLUMN IF NOT EXISTS remark varchar(100) DEFAULT NULL;

ALTER TABLE dnsmgr_dmtask ADD COLUMN IF NOT EXISTS proxy smallint NOT NULL DEFAULT 0;

ALTER TABLE dnsmgr_user ADD COLUMN IF NOT EXISTS totp_open smallint NOT NULL DEFAULT 0;
ALTER TABLE dnsmgr_user ADD COLUMN IF NOT EXISTS totp_secret varchar(100) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS dnsmgr_cert_account (
  id serial PRIMARY KEY,
  type varchar(20) NOT NULL,
  name varchar(255) NOT NULL,
  config text DEFAULT NULL,
  ext text DEFAULT NULL,
  remark varchar(100) DEFAULT NULL,
  deploy smallint NOT NULL DEFAULT 0,
  addtime timestamp DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS dnsmgr_cert_order (
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

CREATE TABLE IF NOT EXISTS dnsmgr_cert_domain (
  id serial PRIMARY KEY,
  oid integer NOT NULL,
  domain varchar(255) NOT NULL,
  sort integer NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS dnsmgr_cert_domain_oid_idx ON dnsmgr_cert_domain (oid);

CREATE TABLE IF NOT EXISTS dnsmgr_cert_deploy (
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

CREATE TABLE IF NOT EXISTS dnsmgr_cert_cname (
  id serial PRIMARY KEY,
  domain varchar(255) NOT NULL,
  did integer NOT NULL,
  rr varchar(128) NOT NULL,
  addtime timestamp DEFAULT NULL,
  status smallint NOT NULL DEFAULT 0
);

ALTER TABLE dnsmgr_account ADD COLUMN IF NOT EXISTS proxy smallint NOT NULL DEFAULT 0;

ALTER TABLE dnsmgr_dmtask ADD COLUMN IF NOT EXISTS cdn smallint NOT NULL DEFAULT 0;

ALTER TABLE dnsmgr_domain ADD COLUMN IF NOT EXISTS is_notice smallint NOT NULL DEFAULT 0;
ALTER TABLE dnsmgr_domain ADD COLUMN IF NOT EXISTS regtime timestamp DEFAULT NULL;
ALTER TABLE dnsmgr_domain ADD COLUMN IF NOT EXISTS expiretime timestamp DEFAULT NULL;
ALTER TABLE dnsmgr_domain ADD COLUMN IF NOT EXISTS checktime timestamp DEFAULT NULL;
ALTER TABLE dnsmgr_domain ADD COLUMN IF NOT EXISTS noticetime timestamp DEFAULT NULL;
ALTER TABLE dnsmgr_domain ADD COLUMN IF NOT EXISTS checkstatus smallint NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS dnsmgr_sctask (
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
CREATE INDEX IF NOT EXISTS dnsmgr_sctask_did_idx ON dnsmgr_sctask (did);

ALTER TABLE dnsmgr_account ADD COLUMN IF NOT EXISTS config text DEFAULT NULL;

CREATE TABLE IF NOT EXISTS dnsmgr_domain_alias (
  id serial PRIMARY KEY,
  did integer NOT NULL,
  name varchar(255) NOT NULL
);
CREATE INDEX IF NOT EXISTS dnsmgr_domain_alias_did_idx ON dnsmgr_domain_alias (did);
CREATE INDEX IF NOT EXISTS dnsmgr_domain_alias_name_idx ON dnsmgr_domain_alias (name);
