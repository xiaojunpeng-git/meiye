-- upgrade_key: 20260810-002-cashier-v3-resumed-hang-checkout-reference
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rh_db := DATABASE();
SET @rh_failures := 0;

SELECT COUNT(*) INTO @rh_upgrade_log
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rh_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @rh_request_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rh_db
  AND TABLE_NAME='eb_cashier_v3_checkout_request'
  AND ENGINE='InnoDB';

SET @rh_failures := IF(@rh_upgrade_log=1,0,1)+IF(@rh_request_table=1,0,1);
SELECT @rh_db AS db_name,@rh_request_table AS checkout_request_table_count,@rh_failures AS precheck_failure_count;

SET @rh_finish_sql := IF(@rh_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result','SELECT * FROM STOP_CASHIER_RESUMED_HANG_REFERENCE_PRECHECK_FAILED');
PREPARE rh_finish_stmt FROM @rh_finish_sql; EXECUTE rh_finish_stmt; DEALLOCATE PREPARE rh_finish_stmt;
