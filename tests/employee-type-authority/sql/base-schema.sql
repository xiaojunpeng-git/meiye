SET NAMES utf8mb4;

CREATE TABLE eb_employee (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(128) NOT NULL DEFAULT '',
  phone varchar(32) NOT NULL DEFAULT '',
  status tinyint(1) NOT NULL DEFAULT 1,
  is_del tinyint(1) NOT NULL DEFAULT 0,
  add_time int(11) unsigned NOT NULL DEFAULT 0,
  update_time int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE eb_system_store_staff (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
  store_id bigint(20) unsigned NOT NULL DEFAULT 0,
  staff_name varchar(128) NOT NULL DEFAULT '',
  status tinyint(1) NOT NULL DEFAULT 1,
  is_del tinyint(1) NOT NULL DEFAULT 0,
  is_fencheng tinyint(1) NOT NULL DEFAULT 0,
  is_hezuofang tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_employee_store (employee_id,store_id,is_del,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE eb_employee_change_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL DEFAULT '',
  target_type varchar(64) NOT NULL DEFAULT '',
  target_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source varchar(32) NOT NULL DEFAULT '',
  before_data text,
  after_data text,
  reason varchar(255) NOT NULL DEFAULT '',
  operator_type varchar(32) NOT NULL DEFAULT '',
  operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
  operator_name varchar(128) NOT NULL DEFAULT '',
  operator_ip varchar(64) NOT NULL DEFAULT '',
  request_id varchar(128) NOT NULL DEFAULT '',
  add_time int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_employee_action (employee_id,action,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE eb_system_admin (
  id bigint(20) unsigned NOT NULL,
  account varchar(64) NOT NULL DEFAULT '',
  real_name varchar(64) NOT NULL DEFAULT '',
  level int(11) NOT NULL DEFAULT 1,
  admin_type int(11) NOT NULL DEFAULT 0,
  roles varchar(255) NOT NULL DEFAULT '',
  status tinyint(1) NOT NULL DEFAULT 1,
  is_del tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE eb_system_role (
  id bigint(20) unsigned NOT NULL,
  rules text,
  status tinyint(1) NOT NULL DEFAULT 1,
  type tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE eb_system_menus (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  pid bigint(20) unsigned NOT NULL DEFAULT 0,
  type tinyint(2) NOT NULL DEFAULT 1,
  icon varchar(64) NOT NULL DEFAULT '',
  menu_name varchar(128) NOT NULL DEFAULT '',
  module varchar(64) NOT NULL DEFAULT '',
  controller varchar(128) NOT NULL DEFAULT '',
  action varchar(128) NOT NULL DEFAULT '',
  api_url varchar(255) NOT NULL DEFAULT '',
  methods varchar(32) NOT NULL DEFAULT '',
  params text,
  sort int(11) NOT NULL DEFAULT 0,
  is_show tinyint(1) NOT NULL DEFAULT 0,
  is_show_path tinyint(1) NOT NULL DEFAULT 0,
  access tinyint(1) NOT NULL DEFAULT 1,
  menu_path varchar(255) NOT NULL DEFAULT '',
  path varchar(255) NOT NULL DEFAULT '',
  auth_type tinyint(1) NOT NULL DEFAULT 1,
  header varchar(64) NOT NULL DEFAULT '',
  is_header tinyint(1) NOT NULL DEFAULT 0,
  unique_auth varchar(128) NOT NULL DEFAULT '',
  is_del tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_unique_auth (unique_auth,is_del,type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE eb_organization_write_idempotency (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_token char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL DEFAULT '',
  scope_key varchar(128) NOT NULL DEFAULT '',
  request_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  response_json text,
  add_time int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_request_token (request_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE eb_system_config (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  menu_name varchar(128) NOT NULL DEFAULT '',
  value text,
  is_store tinyint(1) NOT NULL DEFAULT 0,
  relation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_menu_scope (menu_name,is_store,relation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE eb_store_order_cart_info (
  id bigint(20) unsigned NOT NULL,
  staff_yeji text,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO eb_system_menus
  (id,pid,type,icon,menu_name,module,controller,action,api_url,methods,params,
   sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
VALUES
  (100,0,1,'','人员维护','admin','','','','','[]',10,1,0,1,'/admin/setting/staff','',1,'setting',0,'setting-staff-index',0);

INSERT INTO eb_system_role (id,rules,status,type) VALUES
  (10,'100',1,0),
  (11,'100',1,0);

INSERT INTO eb_system_admin (id,account,real_name,level,admin_type,roles,status,is_del) VALUES
  (1,'admin_1','管理员1',0,0,'',1,0),
  (2,'admin_2','管理员2',1,0,'10',1,0),
  (3,'admin_3','管理员3',1,0,'11',1,0),
  (4,'admin_4','代理管理员',0,3,'',1,0);

INSERT INTO eb_system_config (menu_name,value,is_store,relation_id) VALUES
  ('organization_workspace_write_enabled','1',0,0),
  ('organization_workspace_write_allow_legacy','0',0,0);

INSERT INTO eb_employee (id,name,phone,status,is_del) VALUES
  (1,'无任职员工','13900000001',1,0),
  (2,'普通任职员工','13900000002',1,0),
  (3,'旧分成员工','13900000003',1,0),
  (4,'旧合作方员工','13900000004',1,0),
  (5,'多任职冲突员工','13900000005',1,0),
  (6,'只有失效外部标记','13900000006',1,0),
  (7,'已删除员工','13900000007',0,1);

INSERT INTO eb_system_store_staff
  (id,employee_id,store_id,staff_name,status,is_del,is_fencheng,is_hezuofang)
VALUES
  (201,2,8,'普通任职员工',1,0,0,0),
  (202,3,8,'旧分成员工',1,0,1,0),
  (203,4,8,'旧合作方员工',1,0,0,1),
  (204,5,8,'多任职普通',1,0,0,0),
  (205,5,9,'多任职外部',1,0,1,0),
  (206,6,8,'已离职外部',0,0,1,1),
  (207,6,9,'已删除外部',1,1,1,1);
