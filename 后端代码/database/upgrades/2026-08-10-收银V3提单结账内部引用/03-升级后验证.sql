-- upgrade_key: 20260810-002-cashier-v3-resumed-hang-checkout-reference
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rh_db := DATABASE();
SET @rh_failures := 0;

SELECT COUNT(*) INTO @rh_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rh_db
  AND TABLE_NAME='eb_cashier_v3_checkout_request'
  AND COLUMN_NAME='resumed_hang_order_id'
  AND COLUMN_TYPE='varchar(64)'
  AND IS_NULLABLE='NO'
  AND COLUMN_DEFAULT='';

SET @rh_failures := IF(@rh_column_count=1,0,1);
SELECT @rh_column_count AS resumed_hang_reference_column_count,@rh_failures AS postcheck_failure_count;

SET @rh_finish_sql := IF(@rh_failures=0,'SELECT ''POSTCHECK_OK'' AS postcheck_result','SELECT * FROM STOP_CASHIER_RESUMED_HANG_REFERENCE_POSTCHECK_FAILED');
PREPARE rh_finish_stmt FROM @rh_finish_sql; EXECUTE rh_finish_stmt; DEALLOCATE PREPARE rh_finish_stmt;
