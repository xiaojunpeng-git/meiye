-- upgrade_key: 20260728-003-cashier-v3-event-outbox
-- Exact post-upgrade verification. The dependency registration and physical
-- C1 schema are rechecked; this package's own registration is intentionally
-- not required because release registration occurs after 03 succeeds.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @db := DATABASE();
SET @failures := 0;

SELECT @db AS db_name, VERSION() AS mysql_version;

SELECT COUNT(*) INTO @upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_database_upgrade_log';
SELECT COUNT(*) INTO @upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

SET @dependency_key_used := 0;
SET @log_query := IF(
  @upgrade_log_exists=1 AND @upgrade_key_column=1,
  'SELECT COUNT(*) INTO @dependency_key_used FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260727-001-cashier-v3-command-idem''',
  'SET @dependency_key_used:=0'
);
PREPARE log_stmt FROM @log_query;
EXECUTE log_stmt;
DEALLOCATE PREPARE log_stmt;

SELECT COUNT(*) INTO @dependency_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);
SELECT SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @dependency_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @dependency_column_count,@dependency_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @dependency_index_count,@dependency_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN (
    'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) dependency_indexes;

SET @expected_dependency_table_sha := '3ce13c50d1c43e01fc2663fc0e934c578d243aa1be669f679b9b3544d026a586';
SET @expected_dependency_column_sha := 'edafee513883f44989fe95210292af52b2a9933e4144b96cb344905fe41687d1';
SET @expected_dependency_index_sha := 'f7b465f9c5e4472166a18c3b06677ddd09b50af89fb7198ba75f2ada75c9d769';
SET @dependency_bad :=
    IF(@dependency_key_used=1,0,1)
  + IF(@dependency_table_count=3,0,1)
  + IF(@dependency_column_count=34,0,1)
  + IF(@dependency_index_count=14,0,1)
  + IF(IFNULL(BINARY @dependency_table_sha=BINARY @expected_dependency_table_sha,0),0,1)
  + IF(IFNULL(BINARY @dependency_column_sha=BINARY @expected_dependency_column_sha,0),0,1)
  + IF(IFNULL(BINARY @dependency_index_sha=BINARY @expected_dependency_index_sha,0),0,1);
SET @failures := @failures
  + IF(@upgrade_log_exists=1,0,1)
  + IF(@upgrade_key_column=1,0,1)
  + @dependency_bad;

SELECT COUNT(*) INTO @target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN (
  'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
  'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
);
SELECT SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @target_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN (
  'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
  'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @target_column_count,@target_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN (
  'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
  'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @target_index_count,@target_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN (
    'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
    'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) target_indexes;

SET @expected_target_table_sha := 'ebc4145ba71f9715422c5302bd9d5dca43a7fbe73a40fc8c7dc0f5001a5ef173';
SET @expected_target_column_sha := '5182878b36ceaa72f14fd3d7ab268ba2e149d418c97877de309d2d41ed78b2fa';
SET @expected_target_index_sha := 'b98e22b93c356602837c9ec6bcf48b9afad9b2bb055418e150fe390f62c399ff';
SET @target_structure_bad :=
    IF(@target_count=4,0,1)
  + IF(@target_column_count=75,0,1)
  + IF(@target_index_count=23,0,1)
  + IF(IFNULL(BINARY @target_table_sha=BINARY @expected_target_table_sha,0),0,1)
  + IF(IFNULL(BINARY @target_column_sha=BINARY @expected_target_column_sha,0),0,1)
  + IF(IFNULL(BINARY @target_index_sha=BINARY @expected_target_index_sha,0),0,1);
SET @failures := @failures + @target_structure_bad;

SET @aggregate_version_zero := 0;
SET @aggregate_query := IF(
  @target_count=4,
  'SELECT COUNT(*) INTO @aggregate_version_zero FROM `eb_cashier_v3_business_event` WHERE `aggregate_version`=0',
  'SET @aggregate_version_zero:=1'
);
PREPARE aggregate_stmt FROM @aggregate_query;
EXECUTE aggregate_stmt;
DEALLOCATE PREPARE aggregate_stmt;
SET @failures := @failures + IF(@aggregate_version_zero=0,0,1);

SELECT COUNT(*) INTO @legacy_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_member_event_outbox';
SET @legacy_rows := 0;
SET @legacy_query := IF(
  @legacy_exists=1,
  'SELECT COUNT(*) INTO @legacy_rows FROM `eb_cashier_v3_member_event_outbox`',
  'SET @legacy_rows:=0'
);
PREPARE legacy_stmt FROM @legacy_query;
EXECUTE legacy_stmt;
DEALLOCATE PREPARE legacy_stmt;
SET @failures := @failures + IF(@legacy_rows=0,0,1);

SELECT
  @dependency_key_used AS dependency_key_used,
  @dependency_table_count AS dependency_table_count,
  @dependency_bad AS dependency_failure_count,
  @target_count AS target_table_count,
  @target_table_sha AS target_table_sha,
  @target_column_count AS target_column_count,
  @target_column_sha AS target_column_sha,
  @target_index_count AS target_index_count,
  @target_index_sha AS target_index_sha,
  @target_structure_bad AS target_structure_failure,
  @aggregate_version_zero AS aggregate_version_zero,
  @legacy_exists AS legacy_table_exists,
  @legacy_rows AS legacy_rows,
  @failures AS verify_failure_count;

SET @finish_sql := IF(
  @failures=0,
  'SELECT ''VERIFY_OK'' AS verify_result',
  'SELECT * FROM STOP_CASHIER_V3_EVENT_OUTBOX_VERIFY_FAILED'
);
PREPARE finish_stmt FROM @finish_sql;
EXECUTE finish_stmt;
DEALLOCATE PREPARE finish_stmt;
