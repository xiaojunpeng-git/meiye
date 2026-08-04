-- upgrade_key: 20260731-002-cashier-v3-balance-ledger-idempotency-ascii
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @log_ok FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_database_upgrade_log' AND COLUMN_NAME='upgrade_key'
  AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';
SET @key_used := 0;
SET @key_sql := IF(@log_ok=1,
  'SELECT COUNT(*) INTO @key_used FROM eb_database_upgrade_log WHERE upgrade_key=''20260731-002-cashier-v3-balance-ledger-idempotency-ascii''',
  'SET @key_used:=0');
PREPARE key_stmt FROM @key_sql; EXECUTE key_stmt; DEALLOCATE PREPARE key_stmt;
SELECT COUNT(*) INTO @table_ok FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_user_money' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @column_ok FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_key'
  AND DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH=128 AND IS_NULLABLE='YES';
SELECT COUNT(*) INTO @ascii_exact FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_key'
  AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin';
SELECT COUNT(*) INTO @unique_exact FROM (
  SELECT NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    SUM(SUB_PART IS NOT NULL) AS prefix_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_user_money'
  GROUP BY INDEX_NAME,NON_UNIQUE
  HAVING NON_UNIQUE=0 AND index_columns='idempotency_key' AND prefix_parts=0
) idempotency_indexes;
SELECT COUNT(*) INTO @non_ascii_count FROM eb_user_money
WHERE idempotency_key IS NOT NULL AND idempotency_key REGEXP '[^ -~]';
SELECT COUNT(*) INTO @duplicate_count FROM (
  SELECT idempotency_key FROM eb_user_money WHERE idempotency_key IS NOT NULL
  GROUP BY idempotency_key HAVING COUNT(*)>1
) duplicates;
SET @state_ok := IF((@key_used=0 AND @ascii_exact IN (0,1)) OR (@key_used=1 AND @ascii_exact=1),1,0);
SET @failures := IF(@log_ok=1,0,1)+IF(@table_ok=1,0,1)+IF(@column_ok=1,0,1)
  +IF(@unique_exact=1,0,1)+IF(@non_ascii_count=0,0,1)+IF(@duplicate_count=0,0,1)+IF(@state_ok=1,0,1);
SELECT @key_used AS upgrade_key_used,@ascii_exact AS ascii_bin_column_count,@unique_exact AS unique_index_count,
  @non_ascii_count AS non_ascii_key_count,@duplicate_count AS duplicate_key_count,@failures AS precheck_failure_count;
SET @finish_sql := IF(@failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_BALANCE_LEDGER_IDEMPOTENCY_CHARSET_PRECHECK_FAILED');
PREPARE finish_stmt FROM @finish_sql; EXECUTE finish_stmt; DEALLOCATE PREPARE finish_stmt;
