-- upgrade_key: 20260728-005-cashier-v3-entitlement-draft
-- Exact metadata and persisted-row verification. Existing valid draft data is allowed.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @c2a1_db := DATABASE();
SET @c2a1_verify_failures := 0;

SELECT @c2a1_db AS db_name, VERSION() AS mysql_version;

-- C1 dependency must remain registered and physically exact.
SELECT COUNT(*) INTO @c2a1_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @c2a1_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

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
    IF(@c2a1_upgrade_log_exists=1,0,1)
  + IF(@c2a1_upgrade_key_column=1,0,1)
  + IF(@c2a1_c1_key_used=1,0,1)
  + IF(@c2a1_c1_table_count=3,0,1)
  + IF(@c2a1_c1_column_count=34,0,1)
  + IF(@c2a1_c1_index_count=14,0,1)
  + IF(IFNULL(BINARY @c2a1_c1_table_sha=BINARY '3ce13c50d1c43e01fc2663fc0e934c578d243aa1be669f679b9b3544d026a586',0),0,1)
  + IF(IFNULL(BINARY @c2a1_c1_column_sha=BINARY 'edafee513883f44989fe95210292af52b2a9933e4144b96cb344905fe41687d1',0),0,1)
  + IF(IFNULL(BINARY @c2a1_c1_index_sha=BINARY 'f7b465f9c5e4472166a18c3b06677ddd09b50af89fb7198ba75f2ada75c9d769',0),0,1);
SET @c2a1_verify_failures := @c2a1_verify_failures + @c2a1_dependency_bad;

-- Recheck every legacy authority after DDL. This closes the window where a
-- required old table, column or index changes after 01 but before verification.
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
SET @c2a1_verify_failures := @c2a1_verify_failures + @c2a1_legacy_contract_bad;

-- Exact target table, column and index signatures generated from 02 on MySQL 5.6.51.
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @c2a1_table_count,@c2a1_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_entitlement_resource_version'
);

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @c2a1_column_count,@c2a1_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_entitlement_resource_version'
);

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @c2a1_index_count,@c2a1_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
    'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
    'eb_cashier_v3_entitlement_resource_version'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) c2a1_indexes;

SET @c2a1_expected_table_sha := '7a22ea0c84fc0b91eed9924805e2f5ece6c6047f19a7667884ce668c8a776499';
SET @c2a1_expected_column_sha := '827c0846d5158e6a9cc5a7e111de2895d34f253f05de9c26afc9859c1d509986';
SET @c2a1_expected_index_sha := '2bfb214c21bb77e5ef969e6bb990d840e7e16b821f20945a035c2ca5c52fa5dd';

SET @c2a1_verify_failures := @c2a1_verify_failures
  + IF(@c2a1_table_count=3,0,1)
  + IF(IFNULL(BINARY @c2a1_table_sha=BINARY @c2a1_expected_table_sha,0),0,1)
  + IF(@c2a1_column_count=38,0,1)
  + IF(IFNULL(BINARY @c2a1_column_sha=BINARY @c2a1_expected_column_sha,0),0,1)
  + IF(@c2a1_index_count=15,0,1)
  + IF(IFNULL(BINARY @c2a1_index_sha=BINARY @c2a1_expected_index_sha,0),0,1);

SELECT COUNT(*) INTO @c2a1_generated_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_entitlement_resource_version'
) AND EXTRA LIKE '%GENERATED%';

SELECT COUNT(*) INTO @c2a1_prefix_index_parts
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME IN (
  'eb_cashier_v3_workspace_draft','eb_cashier_v3_workspace_line',
  'eb_cashier_v3_entitlement_resource_version'
) AND SUB_PART IS NOT NULL;

SELECT COUNT(*) INTO @c2a1_draft_current_version_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2a1_db AND TABLE_NAME='eb_cashier_v3_workspace_draft'
  AND COLUMN_NAME='current_version';

SET @c2a1_verify_failures := @c2a1_verify_failures
  + @c2a1_generated_columns
  + @c2a1_prefix_index_parts
  + @c2a1_draft_current_version_columns;

-- Persisted-row contract. This deliberately allows non-empty tables.
SELECT COUNT(*) INTO @c2a1_draft_row_bad
FROM eb_cashier_v3_workspace_draft
WHERE workspace_id='' OR state_context_id='' OR store_id=0 OR operator_id=0
   OR customer_mode NOT IN ('member','guest')
   OR (customer_mode='member' AND member_id=0)
   OR (customer_mode='guest' AND member_id<>0)
   OR draft_status='' OR line_fingerprint='';

SELECT COUNT(*) INTO @c2a1_line_row_bad
FROM eb_cashier_v3_workspace_line
WHERE workspace_id='' OR line_key=''
   OR line_role NOT IN ('sale','entitlement_service')
   OR quantity=0 OR source_version=0 OR detail_version=0
   OR is_experience NOT IN (0,1)
   OR (line_role='entitlement_service' AND (
        member_id=0 OR holder_id=0 OR source_detail_id=0 OR project_id=0
        OR service_object NOT IN ('self','friend')
   ));

SELECT COUNT(*) INTO @c2a1_resource_row_bad
FROM eb_cashier_v3_entitlement_resource_version
WHERE resource_kind NOT IN ('member','member_benefit_pool','card_holder')
   OR resource_id NOT REGEXP '^[1-9][0-9]*$' OR member_id=0
   OR source_fingerprint='' OR current_version=0 OR last_action='';

SELECT COUNT(*) INTO @c2a1_orphan_or_member_mismatch
FROM eb_cashier_v3_workspace_line l
LEFT JOIN eb_cashier_v3_workspace_draft d
  ON BINARY d.workspace_id=BINARY l.workspace_id
WHERE d.id IS NULL OR d.member_id<>l.member_id;

SET @c2a1_verify_failures := @c2a1_verify_failures
  + @c2a1_draft_row_bad
  + @c2a1_line_row_bad
  + @c2a1_resource_row_bad
  + @c2a1_orphan_or_member_mismatch;

SELECT
  @c2a1_c1_key_used AS c1_dependency_key_used,
  @c2a1_dependency_bad AS dependency_failure_count,
  @c2a1_legacy_table_count AS legacy_table_count,
  @c2a1_legacy_engine_bad AS legacy_engine_bad,
  @c2a1_legacy_column_missing AS legacy_column_missing,
  @c2a1_legacy_primary_bad AS legacy_primary_bad,
  @c2a1_legacy_access_index_missing AS legacy_access_index_missing,
  @c2a1_legacy_contract_bad AS legacy_contract_failure_count,
  @c2a1_table_count AS table_count,
  @c2a1_table_sha AS table_contract_sha,
  @c2a1_column_count AS column_count,
  @c2a1_column_sha AS column_contract_sha,
  @c2a1_index_count AS index_count,
  @c2a1_index_sha AS index_contract_sha,
  @c2a1_generated_columns AS generated_columns,
  @c2a1_prefix_index_parts AS prefix_index_parts,
  @c2a1_draft_current_version_columns AS duplicate_draft_current_version,
  @c2a1_draft_row_bad AS draft_row_bad,
  @c2a1_line_row_bad AS line_row_bad,
  @c2a1_resource_row_bad AS resource_row_bad,
  @c2a1_orphan_or_member_mismatch AS orphan_or_member_mismatch,
  @c2a1_verify_failures AS verify_failure_count;

SET @c2a1_verify_sql := IF(
  @c2a1_verify_failures=0,
  'SELECT ''VERIFY_OK'' AS verify_result',
  'SELECT * FROM STOP_C2A1_ENTITLEMENT_DRAFT_VERIFY_FAILED'
);
PREPARE c2a1_verify_stmt FROM @c2a1_verify_sql;
EXECUTE c2a1_verify_stmt;
DEALLOCATE PREPARE c2a1_verify_stmt;
