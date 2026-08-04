-- upgrade_key: 20260728-005-cashier-v3-entitlement-draft
-- Read-only precheck. Target tables must be all absent or all exactly compatible.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @c2a1_db := DATABASE();
SET @c2a1_failures := 0;

SELECT @c2a1_db AS db_name, VERSION() AS mysql_version;

-- Upgrade registry and unused key.
SELECT COUNT(*) INTO @c2a1_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @c2a1_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

SET @c2a1_upgrade_key_used := 0;
SET @c2a1_log_query := IF(
  @c2a1_upgrade_log_exists=1 AND @c2a1_upgrade_key_column=1,
  'SELECT COUNT(*) INTO @c2a1_upgrade_key_used FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260728-005-cashier-v3-entitlement-draft''',
  'SET @c2a1_upgrade_key_used:=0'
);
PREPARE c2a1_log_stmt FROM @c2a1_log_query;
EXECUTE c2a1_log_stmt;
DEALLOCATE PREPARE c2a1_log_stmt;

SET @c2a1_failures := @c2a1_failures
  + IF(@c2a1_upgrade_log_exists=1,0,1)
  + IF(@c2a1_upgrade_key_column=1,0,1)
  + IF(@c2a1_upgrade_key_used=0,0,1);

-- C1 is the authority for command idempotency, cashier_workspace versions and state contexts.
SET @c2a1_c1_key_used := 0;
SET @c2a1_c1_log_query := IF(
  @c2a1_upgrade_log_exists=1 AND @c2a1_upgrade_key_column=1,
  'SELECT COUNT(*) INTO @c2a1_c1_key_used FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260727-001-cashier-v3-command-idem''',
  'SET @c2a1_c1_key_used:=0'
);
PREPARE c2a1_c1_log_stmt FROM @c2a1_c1_log_query;
EXECUTE c2a1_c1_log_stmt;
DEALLOCATE PREPARE c2a1_c1_log_stmt;

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @c2a1_c1_table_count,@c2a1_c1_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @c2a1_c1_column_count,@c2a1_c1_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @c2a1_c1_index_count,@c2a1_c1_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
    'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) c2a1_c1_indexes;

SET @c2a1_dependency_bad :=
    IF(@c2a1_c1_key_used=1,0,1)
  + IF(@c2a1_c1_table_count=3,0,1)
  + IF(@c2a1_c1_column_count=34,0,1)
  + IF(@c2a1_c1_index_count=14,0,1)
  + IF(IFNULL(BINARY @c2a1_c1_table_sha=BINARY '3ce13c50d1c43e01fc2663fc0e934c578d243aa1be669f679b9b3544d026a586',0),0,1)
  + IF(IFNULL(BINARY @c2a1_c1_column_sha=BINARY 'edafee513883f44989fe95210292af52b2a9933e4144b96cb344905fe41687d1',0),0,1)
  + IF(IFNULL(BINARY @c2a1_c1_index_sha=BINARY 'f7b465f9c5e4472166a18c3b06677ddd09b50af89fb7198ba75f2ada75c9d769',0),0,1);
SET @c2a1_failures := @c2a1_failures + @c2a1_dependency_bad;

-- Legacy entitlement authorities are locked/read by A1. Keep this contract
-- additive: require only used columns and access paths, never a whole-table hash.
DROP TEMPORARY TABLE IF EXISTS _c2a1_required_legacy_column;
CREATE TEMPORARY TABLE _c2a1_required_legacy_column (
  table_name varchar(64) NOT NULL,
  column_name varchar(64) NOT NULL,
  PRIMARY KEY (table_name,column_name)
) ENGINE=Memory;

INSERT INTO _c2a1_required_legacy_column VALUES
('eb_user','uid'),('eb_user','nickname'),('eb_user','real_name'),('eb_user','phone'),
('eb_user','avatar'),('eb_user','status'),('eb_user','is_del'),('eb_user','delete_time'),
('eb_user_card_holder','id'),('eb_user_card_holder','uid'),('eb_user_card_holder','oid'),
('eb_user_card_holder','card_name'),('eb_user_card_holder','card_no'),
('eb_user_card_holder','store_id'),('eb_user_card_holder','write_surplus_times'),
('eb_user_card_holder','write_times'),('eb_user_card_holder','product_type'),
('eb_user_card_holder','write_start'),('eb_user_card_holder','write_end'),
('eb_user_card_holder','is_del'),
('eb_store_order','id'),('eb_store_order','uid'),('eb_store_order','store_id'),
('eb_store_order','paid'),('eb_store_order','is_del'),('eb_store_order','is_system_del'),
('eb_store_order','is_user_del'),('eb_store_order','refund_status'),
('eb_store_order','terminal_action'),('eb_store_order','card_upgrade_use_oid'),
('eb_store_order','order_id'),('eb_store_order','mark'),('eb_store_order','pay_price'),
('eb_store_order','cash_pay_price'),('eb_store_order','yue_pay_price'),
('eb_store_order','debt_amount'),('eb_store_order','repaid_debt_amount'),
('eb_store_order_cart_info','id'),('eb_store_order_cart_info','oid'),
('eb_store_order_cart_info','cart_id'),('eb_store_order_cart_info','product_id'),
('eb_store_order_cart_info','cart_type'),('eb_store_order_cart_info','product_type'),
('eb_store_order_cart_info','cart_info'),('eb_store_order_cart_info','write_times'),
('eb_store_order_cart_info','write_surplus_times'),('eb_store_order_cart_info','is_writeoff'),
('eb_store_order_cart_info','write_start'),('eb_store_order_cart_info','write_end'),
('eb_store_order_cart_info','pay_price'),('eb_store_order_cart_info','debt_amount'),
('eb_store_order_cart_info','repaid_debt_amount'),('eb_store_order_cart_info','is_gift'),
('eb_store_reservation_order','id'),('eb_store_reservation_order','cart_info_id'),
('eb_store_reservation_order','status'),('eb_store_reservation_order','is_del'),
('eb_store_reservation_order','is_system_del'),
('eb_store_debt','id'),('eb_store_debt','order_id'),('eb_store_debt','status'),
('eb_store_debt','total_debt'),('eb_store_debt','repaid_debt');

SELECT COUNT(*) INTO @c2a1_legacy_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_user','eb_user_card_holder','eb_store_order','eb_store_order_cart_info',
  'eb_store_reservation_order','eb_store_debt'
);

SELECT COUNT(*) INTO @c2a1_legacy_engine_bad
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_user','eb_user_card_holder','eb_store_order','eb_store_order_cart_info',
  'eb_store_reservation_order','eb_store_debt'
) AND ENGINE<>'InnoDB';

SELECT COUNT(*) INTO @c2a1_legacy_column_missing
FROM _c2a1_required_legacy_column e
LEFT JOIN information_schema.COLUMNS c
  ON c.TABLE_SCHEMA=@c2a1_db
 AND BINARY c.TABLE_NAME=BINARY e.table_name
 AND BINARY c.COLUMN_NAME=BINARY e.column_name
WHERE c.COLUMN_NAME IS NULL;

DROP TEMPORARY TABLE IF EXISTS _c2a1_expected_legacy_primary;
CREATE TEMPORARY TABLE _c2a1_expected_legacy_primary (
  table_name varchar(64) NOT NULL,
  index_columns varchar(255) NOT NULL,
  PRIMARY KEY (table_name)
) ENGINE=Memory;
INSERT INTO _c2a1_expected_legacy_primary VALUES
('eb_user','uid'),
('eb_user_card_holder','id'),
('eb_store_order','id'),
('eb_store_order_cart_info','id'),
('eb_store_reservation_order','id'),
('eb_store_debt','id');

DROP TEMPORARY TABLE IF EXISTS _c2a1_actual_legacy_primary;
CREATE TEMPORARY TABLE _c2a1_actual_legacy_primary AS
SELECT TABLE_NAME AS table_name,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
       SUM(SUB_PART IS NOT NULL) AS prefix_parts,
       MAX(INDEX_TYPE) AS index_type,
       MAX(NON_UNIQUE) AS non_unique
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2a1_db AND INDEX_NAME='PRIMARY'
  AND TABLE_NAME IN (
    'eb_user','eb_user_card_holder','eb_store_order','eb_store_order_cart_info',
    'eb_store_reservation_order','eb_store_debt'
  )
GROUP BY TABLE_NAME,INDEX_NAME;

SELECT COUNT(*) INTO @c2a1_legacy_primary_bad
FROM _c2a1_expected_legacy_primary e
LEFT JOIN _c2a1_actual_legacy_primary a
  ON BINARY a.table_name=BINARY e.table_name
WHERE a.table_name IS NULL
   OR BINARY a.index_columns<>BINARY e.index_columns
   OR a.prefix_parts<>0
   OR BINARY a.index_type<>BINARY 'BTREE'
   OR a.non_unique<>0;

DROP TEMPORARY TABLE IF EXISTS _c2a1_required_legacy_access;
CREATE TEMPORARY TABLE _c2a1_required_legacy_access (
  table_name varchar(64) NOT NULL,
  leading_column varchar(64) NOT NULL,
  PRIMARY KEY (table_name,leading_column)
) ENGINE=Memory;
INSERT INTO _c2a1_required_legacy_access VALUES
('eb_user_card_holder','uid'),
('eb_user_card_holder','oid'),
('eb_store_order_cart_info','oid'),
('eb_store_reservation_order','cart_info_id'),
('eb_store_debt','order_id');

SELECT COUNT(*) INTO @c2a1_legacy_access_index_missing
FROM _c2a1_required_legacy_access e
LEFT JOIN (
  SELECT DISTINCT TABLE_NAME AS table_name,COLUMN_NAME AS leading_column
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c2a1_db
    AND SEQ_IN_INDEX=1
    AND SUB_PART IS NULL
    AND INDEX_TYPE='BTREE'
) a
  ON BINARY a.table_name=BINARY e.table_name
 AND BINARY a.leading_column=BINARY e.leading_column
WHERE a.leading_column IS NULL;

SET @c2a1_legacy_contract_bad :=
    IF(@c2a1_legacy_table_count=6,0,1)
  + @c2a1_legacy_engine_bad
  + @c2a1_legacy_column_missing
  + @c2a1_legacy_primary_bad
  + @c2a1_legacy_access_index_missing;
SET @c2a1_failures := @c2a1_failures + @c2a1_legacy_contract_bad;

-- Exact target column contract.
DROP TEMPORARY TABLE IF EXISTS _c2a1_expected_column;
CREATE TEMPORARY TABLE _c2a1_expected_column (
  table_name varchar(64) NOT NULL,
  column_name varchar(64) NOT NULL,
  ordinal_position int(11) NOT NULL,
  column_type varchar(128) NOT NULL,
  is_nullable varchar(3) NOT NULL,
  default_is_null tinyint(1) NOT NULL,
  column_default varchar(255) NOT NULL DEFAULT '',
  character_set_name varchar(32) NOT NULL DEFAULT '',
  collation_name varchar(64) NOT NULL DEFAULT '',
  extra_value varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (table_name,column_name)
) ENGINE=Memory;

INSERT INTO _c2a1_expected_column VALUES
('eb_cashier_v3_workspace_draft','id',1,'bigint(20) unsigned','NO',1,'','','','auto_increment'),
('eb_cashier_v3_workspace_draft','workspace_id',2,'varchar(64)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_draft','state_context_id',3,'varchar(64)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_draft','store_id',4,'bigint(20) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_draft','operator_id',5,'bigint(20) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_draft','member_id',6,'bigint(20) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_draft','customer_mode',7,'varchar(16)','NO',0,'guest','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_draft','draft_status',8,'varchar(16)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_draft','line_fingerprint',9,'char(64)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_draft','add_time',10,'int(11) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_draft','update_time',11,'int(11) unsigned','NO',0,'0','','',''),

('eb_cashier_v3_workspace_line','id',1,'bigint(20) unsigned','NO',1,'','','','auto_increment'),
('eb_cashier_v3_workspace_line','workspace_id',2,'varchar(64)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_line','line_key',3,'varchar(64)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_line','line_role',4,'varchar(32)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_line','member_id',5,'bigint(20) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_line','holder_id',6,'bigint(20) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_line','source_detail_id',7,'bigint(20) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_line','project_id',8,'bigint(20) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_line','quantity',9,'int(10) unsigned','NO',0,'1','','',''),
('eb_cashier_v3_workspace_line','source_version',10,'bigint(20) unsigned','NO',0,'1','','',''),
('eb_cashier_v3_workspace_line','detail_version',11,'bigint(20) unsigned','NO',0,'1','','',''),
('eb_cashier_v3_workspace_line','service_object',12,'varchar(16)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_workspace_line','is_experience',13,'tinyint(3) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_line','craftsmen_json',14,'mediumtext','YES',1,'','utf8mb4','utf8mb4_general_ci',''),
('eb_cashier_v3_workspace_line','display_snapshot_json',15,'mediumtext','YES',1,'','utf8mb4','utf8mb4_general_ci',''),
('eb_cashier_v3_workspace_line','sort_no',16,'int(10) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_line','add_time',17,'int(11) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_workspace_line','update_time',18,'int(11) unsigned','NO',0,'0','','',''),

('eb_cashier_v3_entitlement_resource_version','id',1,'bigint(20) unsigned','NO',1,'','','','auto_increment'),
('eb_cashier_v3_entitlement_resource_version','resource_kind',2,'varchar(32)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_entitlement_resource_version','resource_id',3,'varchar(64)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_entitlement_resource_version','member_id',4,'bigint(20) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_entitlement_resource_version','source_fingerprint',5,'char(64)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_entitlement_resource_version','current_version',6,'bigint(20) unsigned','NO',0,'1','','',''),
('eb_cashier_v3_entitlement_resource_version','last_action',7,'varchar(64)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_entitlement_resource_version','add_time',8,'int(11) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_entitlement_resource_version','update_time',9,'int(11) unsigned','NO',0,'0','','','');

DROP TEMPORARY TABLE IF EXISTS _c2a1_expected_index;
CREATE TEMPORARY TABLE _c2a1_expected_index (
  table_name varchar(64) NOT NULL,
  index_name varchar(64) NOT NULL,
  non_unique tinyint(1) NOT NULL,
  index_type varchar(16) NOT NULL,
  index_columns varchar(255) NOT NULL,
  sub_parts varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (table_name,index_name)
) ENGINE=Memory;

INSERT INTO _c2a1_expected_index VALUES
('eb_cashier_v3_workspace_draft','PRIMARY',0,'BTREE','id',''),
('eb_cashier_v3_workspace_draft','uk_workspace_id',0,'BTREE','workspace_id',''),
('eb_cashier_v3_workspace_draft','uk_state_context_id',0,'BTREE','state_context_id',''),
('eb_cashier_v3_workspace_draft','idx_store_operator_time',1,'BTREE','store_id,operator_id,update_time',''),
('eb_cashier_v3_workspace_draft','idx_member_time',1,'BTREE','member_id,update_time',''),
('eb_cashier_v3_workspace_line','PRIMARY',0,'BTREE','id',''),
('eb_cashier_v3_workspace_line','uk_workspace_line',0,'BTREE','workspace_id,line_key',''),
('eb_cashier_v3_workspace_line','idx_workspace_role_sort',1,'BTREE','workspace_id,line_role,sort_no,id',''),
('eb_cashier_v3_workspace_line','idx_member_role',1,'BTREE','member_id,line_role',''),
('eb_cashier_v3_workspace_line','idx_entitlement_source',1,'BTREE','holder_id,source_detail_id,project_id',''),
('eb_cashier_v3_workspace_line','idx_update_time',1,'BTREE','update_time',''),
('eb_cashier_v3_entitlement_resource_version','PRIMARY',0,'BTREE','id',''),
('eb_cashier_v3_entitlement_resource_version','uk_resource',0,'BTREE','resource_kind,resource_id',''),
('eb_cashier_v3_entitlement_resource_version','idx_member_kind',1,'BTREE','member_id,resource_kind',''),
('eb_cashier_v3_entitlement_resource_version','idx_update_time',1,'BTREE','update_time','');

SELECT COUNT(*) INTO @c2a1_target_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_entitlement_resource_version'
);

SET @c2a1_partial_tables := IF(@c2a1_target_table_count IN (0,3),0,1);
SET @c2a1_failures := @c2a1_failures + @c2a1_partial_tables;

DROP TEMPORARY TABLE IF EXISTS _c2a1_actual_column;
CREATE TEMPORARY TABLE _c2a1_actual_column AS
SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name,
       ORDINAL_POSITION AS ordinal_position,COLUMN_TYPE AS column_type,
       IS_NULLABLE AS is_nullable,IF(COLUMN_DEFAULT IS NULL,1,0) AS default_is_null,
       IFNULL(COLUMN_DEFAULT,'') AS column_default,
       IFNULL(CHARACTER_SET_NAME,'') AS character_set_name,
       IFNULL(COLLATION_NAME,'') AS collation_name,IFNULL(EXTRA,'') AS extra_value
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_entitlement_resource_version'
);

DROP TEMPORARY TABLE IF EXISTS _c2a1_actual_index;
CREATE TEMPORARY TABLE _c2a1_actual_index AS
SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
       MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
       IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_entitlement_resource_version'
)
GROUP BY TABLE_NAME,INDEX_NAME;

SELECT COUNT(*) INTO @c2a1_table_meta_bad
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_entitlement_resource_version'
) AND (ENGINE<>'InnoDB' OR TABLE_COLLATION<>'utf8mb4_general_ci');

SELECT COUNT(*) INTO @c2a1_column_missing
FROM _c2a1_expected_column e
LEFT JOIN _c2a1_actual_column a
  ON BINARY a.table_name=BINARY e.table_name AND BINARY a.column_name=BINARY e.column_name
WHERE a.column_name IS NULL;

SELECT COUNT(*) INTO @c2a1_column_extra
FROM _c2a1_actual_column a
LEFT JOIN _c2a1_expected_column e
  ON BINARY e.table_name=BINARY a.table_name AND BINARY e.column_name=BINARY a.column_name
WHERE e.column_name IS NULL;

SELECT COUNT(*) INTO @c2a1_column_bad
FROM _c2a1_expected_column e
JOIN _c2a1_actual_column a
  ON BINARY a.table_name=BINARY e.table_name AND BINARY a.column_name=BINARY e.column_name
WHERE a.ordinal_position<>e.ordinal_position
   OR BINARY a.column_type<>BINARY e.column_type
   OR BINARY a.is_nullable<>BINARY e.is_nullable
   OR a.default_is_null<>e.default_is_null
   OR BINARY a.column_default<>BINARY e.column_default
   OR BINARY a.character_set_name<>BINARY e.character_set_name
   OR BINARY a.collation_name<>BINARY e.collation_name
   OR BINARY a.extra_value<>BINARY e.extra_value;

SELECT COUNT(*) INTO @c2a1_index_missing
FROM _c2a1_expected_index e
LEFT JOIN _c2a1_actual_index a
  ON BINARY a.table_name=BINARY e.table_name AND BINARY a.index_name=BINARY e.index_name
WHERE a.index_name IS NULL;

SELECT COUNT(*) INTO @c2a1_index_extra
FROM _c2a1_actual_index a
LEFT JOIN _c2a1_expected_index e
  ON BINARY e.table_name=BINARY a.table_name AND BINARY e.index_name=BINARY a.index_name
WHERE e.index_name IS NULL;

SELECT COUNT(*) INTO @c2a1_index_bad
FROM _c2a1_expected_index e
JOIN _c2a1_actual_index a
  ON BINARY a.table_name=BINARY e.table_name AND BINARY a.index_name=BINARY e.index_name
WHERE a.non_unique<>e.non_unique
   OR BINARY a.index_type<>BINARY e.index_type
   OR BINARY a.index_columns<>BINARY e.index_columns
   OR BINARY a.sub_parts<>BINARY e.sub_parts;

SET @c2a1_target_structure_bad := IF(
  @c2a1_target_table_count=3,
  @c2a1_table_meta_bad+@c2a1_column_missing+@c2a1_column_extra+@c2a1_column_bad
    +@c2a1_index_missing+@c2a1_index_extra+@c2a1_index_bad,
  0
);
SET @c2a1_failures := @c2a1_failures + @c2a1_target_structure_bad;

-- Existing exact tables may contain data, but versions and identities must remain valid.
SET @c2a1_row_contract_bad := 0;
SET @c2a1_row_query := IF(
  @c2a1_target_table_count=3 AND @c2a1_target_structure_bad=0,
  'SELECT (SELECT COUNT(*) FROM eb_cashier_v3_workspace_draft WHERE workspace_id='''' OR state_context_id='''' OR store_id=0 OR operator_id=0 OR customer_mode NOT IN (''member'',''guest'') OR (customer_mode=''member'' AND member_id=0) OR (customer_mode=''guest'' AND member_id<>0) OR draft_status='''' OR line_fingerprint='''') + (SELECT COUNT(*) FROM eb_cashier_v3_workspace_line WHERE workspace_id='''' OR line_key='''' OR line_role NOT IN (''sale'',''entitlement_service'') OR quantity=0 OR source_version=0 OR detail_version=0 OR is_experience NOT IN (0,1) OR (line_role=''entitlement_service'' AND (member_id=0 OR holder_id=0 OR source_detail_id=0 OR project_id=0 OR service_object NOT IN (''self'',''friend'')))) + (SELECT COUNT(*) FROM eb_cashier_v3_entitlement_resource_version WHERE resource_kind NOT IN (''member'',''member_benefit_pool'',''card_holder'') OR resource_id NOT REGEXP ''^[1-9][0-9]*$'' OR member_id=0 OR source_fingerprint='''' OR current_version=0 OR last_action='''') INTO @c2a1_row_contract_bad',
  'SET @c2a1_row_contract_bad:=0'
);
PREPARE c2a1_row_stmt FROM @c2a1_row_query;
EXECUTE c2a1_row_stmt;
DEALLOCATE PREPARE c2a1_row_stmt;
SET @c2a1_failures := @c2a1_failures + @c2a1_row_contract_bad;

SELECT
  @c2a1_upgrade_key_used AS upgrade_key_used,
  @c2a1_c1_key_used AS c1_dependency_key_used,
  @c2a1_dependency_bad AS dependency_failure_count,
  @c2a1_legacy_table_count AS legacy_table_count,
  @c2a1_legacy_engine_bad AS legacy_engine_bad,
  @c2a1_legacy_column_missing AS legacy_column_missing,
  @c2a1_legacy_primary_bad AS legacy_primary_bad,
  @c2a1_legacy_access_index_missing AS legacy_access_index_missing,
  @c2a1_legacy_contract_bad AS legacy_contract_failure_count,
  @c2a1_target_table_count AS target_table_count,
  @c2a1_partial_tables AS partial_table_failure,
  @c2a1_table_meta_bad AS table_meta_bad,
  @c2a1_column_missing AS column_missing,
  @c2a1_column_extra AS column_extra,
  @c2a1_column_bad AS column_bad,
  @c2a1_index_missing AS index_missing,
  @c2a1_index_extra AS index_extra,
  @c2a1_index_bad AS index_bad,
  @c2a1_row_contract_bad AS row_contract_bad,
  @c2a1_failures AS precheck_failure_count;

SET @c2a1_finish_sql := IF(
  @c2a1_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_C2A1_ENTITLEMENT_DRAFT_PRECHECK_FAILED'
);
PREPARE c2a1_finish_stmt FROM @c2a1_finish_sql;
EXECUTE c2a1_finish_stmt;
DEALLOCATE PREPARE c2a1_finish_stmt;
