-- upgrade_key: 20260728-002-cashier-v3-member-consistency
-- Exact post-upgrade metadata verification.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @c5_db := DATABASE();
SET @c5_verify_failures := 0;

SELECT @c5_db AS db_name, VERSION() AS mysql_version;

-- Recheck both registered and physical dependency contracts. This package's
-- own upgrade-log row is intentionally written only after 03 succeeds.
SELECT COUNT(*) INTO @c5_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_database_upgrade_log';
SELECT COUNT(*) INTO @c5_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';
SET @c5_c1_key_used := 0;
SET @c5_event_key_used := 0;
SET @c5_dependency_log_query := IF(
  @c5_upgrade_log_exists=1 AND @c5_upgrade_key_column=1,
  'SELECT SUM(`upgrade_key`=''20260727-001-cashier-v3-command-idem''), SUM(`upgrade_key`=''20260728-003-cashier-v3-event-outbox'') INTO @c5_c1_key_used,@c5_event_key_used FROM `eb_database_upgrade_log`',
  'SET @c5_c1_key_used:=0,@c5_event_key_used:=0'
);
PREPARE c5_dependency_log_stmt FROM @c5_dependency_log_query;
EXECUTE c5_dependency_log_stmt;
DEALLOCATE PREPARE c5_dependency_log_stmt;
SET @c5_c1_key_used := IFNULL(@c5_c1_key_used,0);
SET @c5_event_key_used := IFNULL(@c5_event_key_used,0);

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @c5_c1_table_count,@c5_c1_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @c5_c1_column_count,@c5_c1_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @c5_c1_index_count,@c5_c1_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
    'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) c5_c1_indexes;

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @c5_event_table_count,@c5_event_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
  'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @c5_event_column_count,@c5_event_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
  'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @c5_event_index_count,@c5_event_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
    'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
    'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) c5_event_indexes;

SET @c5_dependency_bad :=
    IF(@c5_upgrade_log_exists=1,0,1)
  + IF(@c5_upgrade_key_column=1,0,1)
  + IF(@c5_c1_key_used=1,0,1)
  + IF(@c5_event_key_used=1,0,1)
  + IF(@c5_c1_table_count=3,0,1)
  + IF(@c5_c1_column_count=34,0,1)
  + IF(@c5_c1_index_count=14,0,1)
  + IF(IFNULL(BINARY @c5_c1_table_sha=BINARY '3ce13c50d1c43e01fc2663fc0e934c578d243aa1be669f679b9b3544d026a586',0),0,1)
  + IF(IFNULL(BINARY @c5_c1_column_sha=BINARY 'edafee513883f44989fe95210292af52b2a9933e4144b96cb344905fe41687d1',0),0,1)
  + IF(IFNULL(BINARY @c5_c1_index_sha=BINARY 'f7b465f9c5e4472166a18c3b06677ddd09b50af89fb7198ba75f2ada75c9d769',0),0,1)
  + IF(@c5_event_table_count=4,0,1)
  + IF(@c5_event_column_count=75,0,1)
  + IF(@c5_event_index_count=23,0,1)
  + IF(IFNULL(BINARY @c5_event_table_sha=BINARY 'ebc4145ba71f9715422c5302bd9d5dca43a7fbe73a40fc8c7dc0f5001a5ef173',0),0,1)
  + IF(IFNULL(BINARY @c5_event_column_sha=BINARY '5182878b36ceaa72f14fd3d7ab268ba2e149d418c97877de309d2d41ed78b2fa',0),0,1)
  + IF(IFNULL(BINARY @c5_event_index_sha=BINARY 'b98e22b93c356602837c9ec6bcf48b9afad9b2bb055418e150fe390f62c399ff',0),0,1);
SET @c5_verify_failures := @c5_verify_failures + @c5_dependency_bad;

SELECT COUNT(*) INTO @c5_legacy_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_cashier_v3_member_event_outbox';
SET @c5_legacy_rows := 0;
SET @c5_legacy_query := IF(
  @c5_legacy_exists=1,
  'SELECT COUNT(*) INTO @c5_legacy_rows FROM `eb_cashier_v3_member_event_outbox`',
  'SET @c5_legacy_rows:=0'
);
PREPARE c5_legacy_stmt FROM @c5_legacy_query;
EXECUTE c5_legacy_stmt;
DEALLOCATE PREPARE c5_legacy_stmt;
SET @c5_verify_failures := @c5_verify_failures + IF(@c5_legacy_rows=0,0,1);

SELECT COUNT(*) INTO @c5_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_member_phone_lock','eb_cashier_v3_member_number_sequence',
  'eb_member_exclusive_service',
  'eb_member_exclusive_service_change'
);

SELECT SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @c5_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_member_phone_lock','eb_cashier_v3_member_number_sequence',
  'eb_member_exclusive_service',
  'eb_member_exclusive_service_change'
);

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256)
INTO @c5_column_count,@c5_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_member_phone_lock','eb_cashier_v3_member_number_sequence',
  'eb_member_exclusive_service',
  'eb_member_exclusive_service_change'
);

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256)
INTO @c5_index_count,@c5_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
         MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
         GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
         IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
    'eb_cashier_v3_member_phone_lock','eb_cashier_v3_member_number_sequence',
    'eb_member_exclusive_service',
    'eb_member_exclusive_service_change'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) c5_indexes;

-- These signatures are generated from 02 on MySQL 5.6.51 and intentionally
-- bind column order, types, defaults, charset/collation, extras and all indexes.
SET @c5_expected_table_sha := 'c8f55e24c49a7c0a698050d1f0694f5abbb4baa7135066ef7c8bb908d13c45fd';
SET @c5_expected_column_sha := 'a9bb28f84327d614d301d0c927a96c9cfb479950f311f16aabd03c79b40bc89f';
SET @c5_expected_index_sha := '80de3788dd7cfc00ddd51aa58b0bab43257d271e4c61275e90eb2f027f6f4387';

SET @c5_verify_failures := @c5_verify_failures
  + IF(@c5_table_count=4,0,1)
  + IF(BINARY @c5_table_sha=BINARY @c5_expected_table_sha,0,1)
  + IF(@c5_column_count=49,0,1)
  + IF(BINARY @c5_column_sha=BINARY @c5_expected_column_sha,0,1)
  + IF(@c5_index_count=14,0,1)
  + IF(BINARY @c5_index_sha=BINARY @c5_expected_index_sha,0,1);

SELECT COUNT(*) INTO @c5_bar_index_exact
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_user' AND INDEX_NAME='idx_bar_code'
  AND NON_UNIQUE=1 AND SEQ_IN_INDEX=1 AND COLUMN_NAME='bar_code'
  AND SUB_PART IS NULL AND INDEX_TYPE='BTREE';

SELECT COUNT(*) INTO @c5_bar_index_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_user' AND INDEX_NAME='idx_bar_code';

SELECT COUNT(*) INTO @c5_sequence_seed
FROM eb_cashier_v3_member_number_sequence
WHERE sequence_key='member_bar_code'
  AND current_value BETWEEN 99999999 AND 999999999;

SELECT COUNT(*) INTO @c5_generated_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_member_phone_lock','eb_cashier_v3_member_number_sequence',
  'eb_member_exclusive_service',
  'eb_member_exclusive_service_change'
) AND EXTRA LIKE '%GENERATED%';

SET @c5_verify_failures := @c5_verify_failures
  + IF(@c5_bar_index_rows=1 AND @c5_bar_index_exact=1,0,1)
  + IF(@c5_sequence_seed=1,0,1)
  + IF(@c5_generated_columns=0,0,1);

SELECT
  @c5_c1_key_used AS c1_dependency_key_used,
  @c5_event_key_used AS event_dependency_key_used,
  @c5_dependency_bad AS dependency_failure_count,
  @c5_legacy_exists AS legacy_table_exists,
  @c5_legacy_rows AS legacy_rows,
  @c5_table_count AS table_count,
  @c5_table_sha AS table_contract_sha,
  @c5_column_count AS column_count,
  @c5_column_sha AS column_contract_sha,
  @c5_index_count AS index_count,
  @c5_index_sha AS index_contract_sha,
  @c5_bar_index_rows AS bar_code_index_rows,
  @c5_bar_index_exact AS bar_code_index_exact,
  @c5_sequence_seed AS sequence_seed_ok,
  @c5_generated_columns AS generated_columns,
  @c5_verify_failures AS verify_failure_count;

SET @c5_verify_sql := IF(
  @c5_verify_failures=0,
  'SELECT ''VERIFY_OK'' AS verify_result',
  'SELECT * FROM STOP_C5_MEMBER_VERIFY_FAILED'
);
PREPARE c5_verify_stmt FROM @c5_verify_sql;
EXECUTE c5_verify_stmt;
DEALLOCATE PREPARE c5_verify_stmt;
