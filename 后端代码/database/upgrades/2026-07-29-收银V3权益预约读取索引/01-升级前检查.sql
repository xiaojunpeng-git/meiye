-- upgrade_key: 20260729-018-cashier-v3-entitlement-reservation-read-index-v1
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @v3r_db := DATABASE();
SET @v3r_failures := 0;

SELECT @v3r_db AS db_name, VERSION() AS mysql_version;

SELECT COUNT(*) INTO @v3r_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_database_upgrade_log';
SELECT COUNT(*) INTO @v3r_log_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';
SET @v3r_key_used := 0;
SET @v3r_log_sql := IF(
  @v3r_log_exists=1 AND @v3r_log_key_column=1,
  'SELECT COUNT(*) INTO @v3r_key_used FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260729-018-cashier-v3-entitlement-reservation-read-index-v1''',
  'SET @v3r_key_used:=0'
);
PREPARE v3r_log_stmt FROM @v3r_log_sql;
EXECUTE v3r_log_stmt;
DEALLOCATE PREPARE v3r_log_stmt;

SELECT COUNT(*) INTO @v3r_table_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_store_reservation_order'
  AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @v3r_required_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_store_reservation_order'
  AND ((COLUMN_NAME='id') OR (COLUMN_NAME='cart_info_id' AND DATA_TYPE='int'));

SELECT COUNT(*) INTO @v3r_named_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_store_reservation_order'
  AND INDEX_NAME='idx_c2_entitlement_cart_info';
SELECT COUNT(*) INTO @v3r_named_exact_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_store_reservation_order'
  AND INDEX_NAME='idx_c2_entitlement_cart_info' AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE'
  AND SUB_PART IS NULL
  AND CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME) IN ('1:cart_info_id','2:id');

SELECT COUNT(*) INTO @v3r_equivalent_index_count
FROM (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@v3r_db AND TABLE_NAME='eb_store_reservation_order'
    AND NON_UNIQUE=1 AND INDEX_TYPE='BTREE' AND SUB_PART IS NULL
  GROUP BY INDEX_NAME
  HAVING COUNT(*)=2
    AND SUM(CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME)='1:cart_info_id')=1
    AND SUM(CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME)='2:id')=1
) v3r_equivalent_indexes;

SET @v3r_failures := @v3r_failures
  + IF(@v3r_log_exists=1,0,1)
  + IF(@v3r_log_key_column=1,0,1)
  + IF(@v3r_key_used=0,0,1)
  + IF(@v3r_table_ready=1,0,1)
  + IF(@v3r_required_columns=2,0,1)
  + IF(@v3r_named_rows=0 OR (@v3r_named_rows=2 AND @v3r_named_exact_rows=2),0,1);

SET @v3r_failure_codes := CONCAT_WS(',',
  IF(@v3r_log_exists=1,NULL,'V3R_UPGRADE_LOG_MISSING'),
  IF(@v3r_log_key_column=1,NULL,'V3R_UPGRADE_LOG_CONTRACT_INVALID'),
  IF(@v3r_key_used=0,NULL,'V3R_UPGRADE_KEY_USED'),
  IF(@v3r_table_ready=1,NULL,'V3R_RESERVATION_TABLE_INVALID'),
  IF(@v3r_required_columns=2,NULL,'V3R_RESERVATION_COLUMNS_INVALID'),
  IF(@v3r_named_rows=0 OR (@v3r_named_rows=2 AND @v3r_named_exact_rows=2),NULL,'V3R_NAMED_INDEX_INVALID')
);

SELECT IF(@v3r_failures=0,'PRECHECK_OK','PRECHECK_FAILED') AS precheck_result,
  @v3r_failures AS failure_count,
  @v3r_failure_codes AS failure_codes,
  @v3r_named_rows AS named_index_rows,
  @v3r_equivalent_index_count AS existing_equivalent_index_count;

SET @v3r_abort_sql := IF(
  @v3r_failures=0,
  'SELECT ''PRECHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_CASHIER_V3_ENTITLEMENT_RESERVATION_INDEX_PRECHECK_FAILED'
);
PREPARE v3r_abort_stmt FROM @v3r_abort_sql;
EXECUTE v3r_abort_stmt;
DEALLOCATE PREPARE v3r_abort_stmt;
