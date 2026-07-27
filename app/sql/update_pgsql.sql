-- PostgreSQL version of dnsmgr update.sql
-- Converted from MySQL syntax

CREATE TABLE IF NOT EXISTS dnsmgr_config (
  key varchar(32) NOT NULL,
  value TEXT DEFAULT NULL,
  PRIMARY KEY (key)
);

CREATE TABLE IF NOT EXISTS dnsmgr_dmtask (
  id SERIAL PRIMARY KEY,
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
CREATE INDEX idx_dnsmgr_dmtask_did ON dnsmgr_dmtask (did);

CREATE TABLE IF NOT EXISTS dnsmgr_dmlog (
  id SERIAL PRIMARY KEY,
  taskid integer NOT NULL,
  action smallint NOT NULL DEFAULT 0,
  errmsg varchar(100) DEFAULT NULL,
  date timestamp DEFAULT NULL
);
CREATE INDEX idx_dnsmgr_dmlog_taskid ON dnsmgr_dmlog (taskid);
CREATE INDEX idx_dnsmgr_dmlog_date ON dnsmgr_dmlog (date);

CREATE TABLE IF NOT EXISTS dnsmgr_optimizeip (
  id SERIAL PRIMARY KEY,
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
CREATE INDEX idx_dnsmgr_optimizeip_did ON dnsmgr_optimizeip (did);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_domain' AND column_name = 'remark') THEN
        ALTER TABLE dnsmgr_domain ADD COLUMN remark varchar(100) DEFAULT NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_dmtask' AND column_name = 'proxy') THEN
        ALTER TABLE dnsmgr_dmtask ADD COLUMN proxy smallint NOT NULL DEFAULT 0;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_user' AND column_name = 'totp_open') THEN
        ALTER TABLE dnsmgr_user ADD COLUMN totp_open smallint NOT NULL DEFAULT '0';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_user' AND column_name = 'totp_secret') THEN
        ALTER TABLE dnsmgr_user ADD COLUMN totp_secret varchar(100) DEFAULT NULL;
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS dnsmgr_cert_account (
  id SERIAL PRIMARY KEY,
  type varchar(20) NOT NULL,
  name varchar(255) NOT NULL,
  config text DEFAULT NULL,
  ext text DEFAULT NULL,
  remark varchar(100) DEFAULT NULL,
  deploy smallint NOT NULL DEFAULT '0',
  addtime timestamp DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS dnsmgr_cert_order (
  id SERIAL PRIMARY KEY,
  aid integer NOT NULL,
  keytype varchar(20) DEFAULT NULL,
  keysize varchar(20) DEFAULT NULL,
  addtime timestamp DEFAULT NULL,
  updatetime timestamp DEFAULT NULL,
  processid varchar(32) DEFAULT NULL,
  issuetime timestamp DEFAULT NULL,
  expiretime timestamp DEFAULT NULL,
  issuer varchar(100) DEFAULT NULL,
  status smallint NOT NULL DEFAULT '0',
  error varchar(300) DEFAULT NULL,
  isauto smallint NOT NULL DEFAULT '0',
  retry smallint NOT NULL DEFAULT '0',
  retry2 smallint NOT NULL DEFAULT '0',
  retrytime timestamp DEFAULT NULL,
  islock smallint NOT NULL DEFAULT '0',
  locktime timestamp DEFAULT NULL,
  issend smallint NOT NULL DEFAULT '0',
  info text DEFAULT NULL,
  dns text DEFAULT NULL,
  fullchain text DEFAULT NULL,
  privatekey text DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS dnsmgr_cert_domain (
  id SERIAL PRIMARY KEY,
  oid integer NOT NULL,
  domain varchar(255) NOT NULL,
  sort integer NOT NULL DEFAULT '0'
);
CREATE INDEX idx_dnsmgr_cert_domain_oid ON dnsmgr_cert_domain (oid);

CREATE TABLE IF NOT EXISTS dnsmgr_cert_deploy (
  id SERIAL PRIMARY KEY,
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
  retry smallint NOT NULL DEFAULT '0',
  retrytime timestamp DEFAULT NULL,
  islock smallint NOT NULL DEFAULT '0',
  locktime timestamp DEFAULT NULL,
  issend smallint NOT NULL DEFAULT '0',
  info text DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS dnsmgr_cert_cname (
  id SERIAL PRIMARY KEY,
  domain varchar(255) NOT NULL,
  did integer NOT NULL,
  rr varchar(128) NOT NULL,
  addtime timestamp DEFAULT NULL,
  status smallint NOT NULL DEFAULT 0
);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_account' AND column_name = 'proxy') THEN
        ALTER TABLE dnsmgr_account ADD COLUMN proxy smallint NOT NULL DEFAULT '0';
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_dmtask' AND column_name = 'cdn') THEN
        ALTER TABLE dnsmgr_dmtask ADD COLUMN cdn smallint NOT NULL DEFAULT 0;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_domain' AND column_name = 'is_notice') THEN
        ALTER TABLE dnsmgr_domain ADD COLUMN is_notice smallint NOT NULL DEFAULT '0';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_domain' AND column_name = 'regtime') THEN
        ALTER TABLE dnsmgr_domain ADD COLUMN regtime timestamp DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_domain' AND column_name = 'expiretime') THEN
        ALTER TABLE dnsmgr_domain ADD COLUMN expiretime timestamp DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_domain' AND column_name = 'checktime') THEN
        ALTER TABLE dnsmgr_domain ADD COLUMN checktime timestamp DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_domain' AND column_name = 'noticetime') THEN
        ALTER TABLE dnsmgr_domain ADD COLUMN noticetime timestamp DEFAULT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_domain' AND column_name = 'checkstatus') THEN
        ALTER TABLE dnsmgr_domain ADD COLUMN checkstatus smallint NOT NULL DEFAULT '0';
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS dnsmgr_sctask (
  id SERIAL PRIMARY KEY,
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
CREATE INDEX idx_dnsmgr_sctask_did ON dnsmgr_sctask (did);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_account' AND column_name = 'config') THEN
        ALTER TABLE dnsmgr_account ADD COLUMN config text DEFAULT NULL;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_account' AND column_name = 'ak') THEN
        ALTER TABLE dnsmgr_account RENAME COLUMN ak TO name;
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS dnsmgr_domain_alias (
  id SERIAL PRIMARY KEY,
  did integer NOT NULL,
  name varchar(255) NOT NULL
);
CREATE INDEX idx_dnsmgr_domain_alias_did ON dnsmgr_domain_alias (did);
CREATE INDEX idx_dnsmgr_domain_alias_name ON dnsmgr_domain_alias (name);

CREATE TABLE IF NOT EXISTS dnsmgr_domain_category (
  id SERIAL PRIMARY KEY,
  name varchar(50) NOT NULL,
  remark varchar(100) DEFAULT NULL,
  sort integer NOT NULL DEFAULT '0',
  addtime timestamp DEFAULT NULL
);
CREATE INDEX idx_dnsmgr_domain_category_sort ON dnsmgr_domain_category (sort);

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'dnsmgr_domain' AND column_name = 'cid') THEN
        ALTER TABLE dnsmgr_domain ADD COLUMN cid integer NOT NULL DEFAULT '0';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_dnsmgr_domain_cid') THEN
        CREATE INDEX idx_dnsmgr_domain_cid ON dnsmgr_domain (cid);
    END IF;
END $$;
