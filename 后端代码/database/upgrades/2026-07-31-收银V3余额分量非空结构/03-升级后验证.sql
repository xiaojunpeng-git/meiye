-- upgrade_key: 20260731-003-cashier-v3-balance-components-not-null
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @not_null_count FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND ((TABLE_NAME='eb_user' AND COLUMN_NAME IN ('ben_money','give_money'))
  OR (TABLE_NAME='eb_user_money' AND COLUMN_NAME IN ('ben_money','give_money')))
  AND DATA_TYPE='decimal' AND NUMERIC_SCALE=2 AND IS_NULLABLE='NO';
SELECT @not_null_count AS exact_not_null_component_count;
SET @finish_sql := IF(@not_null_count=4,'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_BALANCE_COMPONENTS_NOT_NULL_POSTCHECK_FAILED');
PREPARE finish_stmt FROM @finish_sql; EXECUTE finish_stmt; DEALLOCATE PREPARE finish_stmt;
