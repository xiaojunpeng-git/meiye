-- upgrade_key: 20260731-003-cashier-v3-balance-components-not-null
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @log_ok FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_database_upgrade_log' AND COLUMN_NAME='upgrade_key'
  AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';
SET @key_used := 0;
SET @key_sql := IF(@log_ok=1,
  'SELECT COUNT(*) INTO @key_used FROM eb_database_upgrade_log WHERE upgrade_key=''20260731-003-cashier-v3-balance-components-not-null''',
  'SET @key_used:=0');
PREPARE key_stmt FROM @key_sql; EXECUTE key_stmt; DEALLOCATE PREPARE key_stmt;
SELECT COUNT(*) INTO @columns_ok FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND ((TABLE_NAME='eb_user' AND COLUMN_NAME IN ('ben_money','give_money'))
  OR (TABLE_NAME='eb_user_money' AND COLUMN_NAME IN ('ben_money','give_money')))
  AND DATA_TYPE='decimal' AND NUMERIC_SCALE=2;
SELECT COUNT(*) INTO @not_null_count FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND ((TABLE_NAME='eb_user' AND COLUMN_NAME IN ('ben_money','give_money'))
  OR (TABLE_NAME='eb_user_money' AND COLUMN_NAME IN ('ben_money','give_money')))
  AND IS_NULLABLE='NO';
SELECT (SELECT COUNT(*) FROM eb_user WHERE ben_money IS NULL OR give_money IS NULL)
  +(SELECT COUNT(*) FROM eb_user_money WHERE ben_money IS NULL OR give_money IS NULL)
INTO @historical_null_count;
SET @state_ok := IF((@key_used=0 AND @not_null_count BETWEEN 0 AND 4)
  OR (@key_used=1 AND @not_null_count=4),1,0);
SET @failures := IF(@log_ok=1,0,1)+IF(@columns_ok=4,0,1)+IF(@historical_null_count=0,0,1)+IF(@state_ok=1,0,1);
SELECT @key_used AS upgrade_key_used,@columns_ok AS decimal_scale_two_column_count,@not_null_count AS already_not_null_count,
  @historical_null_count AS historical_null_count,@failures AS precheck_failure_count;
SET @finish_sql := IF(@failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_BALANCE_COMPONENTS_NOT_NULL_PRECHECK_FAILED');
PREPARE finish_stmt FROM @finish_sql; EXECUTE finish_stmt; DEALLOCATE PREPARE finish_stmt;
