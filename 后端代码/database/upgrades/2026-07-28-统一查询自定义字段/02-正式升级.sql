-- upgrade_key: 20260728-004-unified-query-custom-fields
-- MySQL 5.6 compatible: no JSON type, CTE, window function, generated column or CHECK.
-- 01 must pass first. CREATE IF NOT EXISTS supports exact replay only; it does not repair drift.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS eb_unified_query_custom_field (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  field_key varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  page_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  name varchar(64) NOT NULL DEFAULT '',
  name_namespace varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  active_name_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  return_type varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  visibility varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  scope_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  scope_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  owner_account_id bigint(20) unsigned NOT NULL DEFAULT '0',
  status varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  invalid_reason varchar(255) NOT NULL DEFAULT '',
  current_version bigint(20) unsigned NOT NULL DEFAULT '1',
  expression_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  complexity_score smallint(5) unsigned NOT NULL DEFAULT '0',
  uses_aggregate tinyint(3) unsigned NOT NULL DEFAULT '0',
  created_by bigint(20) unsigned NOT NULL DEFAULT '0',
  updated_by bigint(20) unsigned NOT NULL DEFAULT '0',
  created_at int(11) unsigned NOT NULL DEFAULT '0',
  updated_at int(11) unsigned NOT NULL DEFAULT '0',
  archived_at int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  UNIQUE KEY uk_field_key (tenant_id,field_key),
  UNIQUE KEY uk_active_name (tenant_id,page_code,name_namespace,active_name_key),
  KEY idx_page_visibility_owner_status (tenant_id,page_code,visibility,owner_account_id,status,updated_at,id),
  KEY idx_page_scope_status (tenant_id,page_code,scope_type,scope_id,status,updated_at,id),
  KEY idx_updated (tenant_id,updated_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='unified query custom field current state';

CREATE TABLE IF NOT EXISTS eb_unified_query_custom_field_version (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  custom_field_id bigint(20) unsigned NOT NULL DEFAULT '0',
  tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  field_key varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  version bigint(20) unsigned NOT NULL DEFAULT '1',
  name varchar(64) NOT NULL DEFAULT '',
  return_type varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  expression mediumtext NOT NULL,
  expression_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  referenced_fields text NOT NULL,
  referenced_field_contract text NOT NULL,
  required_permissions text NOT NULL,
  complexity_score smallint(5) unsigned NOT NULL DEFAULT '0',
  uses_aggregate tinyint(3) unsigned NOT NULL DEFAULT '0',
  visibility varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  scope_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  scope_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  status varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  invalid_reason varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT '0',
  created_at int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  UNIQUE KEY uk_field_version (custom_field_id,version),
  KEY idx_tenant_field_version (tenant_id,field_key,version),
  KEY idx_created (tenant_id,created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='immutable unified query custom field versions';

CREATE TABLE IF NOT EXISTS eb_unified_query_field_alias_set (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  account_id bigint(20) unsigned NOT NULL DEFAULT '0',
  page_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  current_version bigint(20) unsigned NOT NULL DEFAULT '1',
  created_at int(11) unsigned NOT NULL DEFAULT '0',
  updated_at int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  UNIQUE KEY uk_alias_set (tenant_id,account_id,page_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='account page field alias optimistic version';

CREATE TABLE IF NOT EXISTS eb_unified_query_field_alias (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  account_id bigint(20) unsigned NOT NULL DEFAULT '0',
  page_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  field_key varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  alias varchar(64) NOT NULL DEFAULT '',
  alias_name_key char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  alias_version bigint(20) unsigned NOT NULL DEFAULT '1',
  created_at int(11) unsigned NOT NULL DEFAULT '0',
  updated_at int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  UNIQUE KEY uk_alias_field (tenant_id,account_id,page_code,field_key),
  UNIQUE KEY uk_alias_name (tenant_id,account_id,page_code,alias_name_key),
  KEY idx_alias_version (tenant_id,account_id,page_code,alias_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='account page field display aliases';

CREATE TABLE IF NOT EXISTS eb_unified_query_field_reference (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  consumer_type varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  consumer_id varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  custom_field_id bigint(20) unsigned NOT NULL DEFAULT '0',
  field_key varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  field_version bigint(20) unsigned NOT NULL DEFAULT '1',
  status varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  invalid_reason varchar(255) NOT NULL DEFAULT '',
  query_cutoff_date date NOT NULL,
  created_at int(11) unsigned NOT NULL DEFAULT '0',
  updated_at int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  UNIQUE KEY uk_consumer_field (tenant_id,consumer_type,consumer_id,field_key),
  KEY idx_field_status (tenant_id,field_key,status),
  KEY idx_consumer_status (tenant_id,consumer_type,consumer_id,status),
  KEY idx_cutoff (tenant_id,query_cutoff_date,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='saved query report and export custom field references';

CREATE TABLE IF NOT EXISTS eb_unified_query_export_task (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  task_no varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  account_id bigint(20) unsigned NOT NULL DEFAULT '0',
  operator_id bigint(20) unsigned NOT NULL DEFAULT '0',
  origin_store_id int(11) unsigned NOT NULL DEFAULT '0',
  origin_organization_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  page_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  export_scope varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'query',
  status varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  query_payload mediumtext NOT NULL,
  field_snapshot mediumtext NOT NULL,
  alias_snapshot text NOT NULL,
  permission_fingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  permission_version varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  frozen_scope mediumtext NOT NULL,
  query_cutoff_date date NOT NULL,
  data_as_of int(11) unsigned NOT NULL DEFAULT '0',
  result_count int(11) unsigned NOT NULL DEFAULT '0',
  include_summary tinyint(3) unsigned NOT NULL DEFAULT '0',
  file_name varchar(255) NOT NULL DEFAULT '',
  storage_key varchar(500) NOT NULL DEFAULT '',
  error_reason varchar(255) NOT NULL DEFAULT '',
  lease_token char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  lease_expires_at int(11) unsigned NOT NULL DEFAULT '0',
  attempt_count int(11) unsigned NOT NULL DEFAULT '0',
  created_at int(11) unsigned NOT NULL DEFAULT '0',
  updated_at int(11) unsigned NOT NULL DEFAULT '0',
  started_at int(11) unsigned NOT NULL DEFAULT '0',
  completed_at int(11) unsigned NOT NULL DEFAULT '0',
  expires_at int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  UNIQUE KEY uk_task_no (task_no),
  KEY idx_account_status_created (tenant_id,account_id,status,created_at,id),
  KEY idx_status_created (tenant_id,status,created_at,id),
  KEY idx_status_lease (status,lease_expires_at,id),
  KEY idx_expiry (status,expires_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='unified query background export tasks';

CREATE TABLE IF NOT EXISTS eb_unified_query_preference (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  tenant_id varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '0',
  account_id bigint(20) unsigned NOT NULL DEFAULT '0',
  page_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  settings mediumtext NOT NULL,
  settings_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  referenced_field_versions text NOT NULL,
  current_version bigint(20) unsigned NOT NULL DEFAULT '1',
  created_at int(11) unsigned NOT NULL DEFAULT '0',
  updated_at int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (id),
  UNIQUE KEY uk_preference (tenant_id,account_id,page_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='account page unified query settings source of truth';

SELECT 'APPLY_OK' AS apply_result;
