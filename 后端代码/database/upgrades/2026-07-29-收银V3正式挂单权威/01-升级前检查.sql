-- upgrade_key: 20260729-017-cashier-v3-hang-order-authority-v1
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ho_db := DATABASE();
SET @ho_failures := 0;

SELECT COUNT(*) INTO @ho_upgrade_log
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ho_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @ho_targets
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ho_db
  AND TABLE_NAME IN ('eb_cashier_v3_hang_order','eb_cashier_v3_hang_order_line');

SET @ho_failures := @ho_failures + IF(@ho_upgrade_log=1,0,1) + IF(@ho_targets=0,0,1);
SELECT @ho_db AS db_name, VERSION() AS mysql_version,
  @ho_upgrade_log AS upgrade_log_count, @ho_targets AS existing_target_count,
  @ho_failures AS precheck_failure_count;

SET @ho_finish := IF(
  @ho_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_HANG_ORDER_PRECHECK_FAILED'
);
PREPARE ho_stmt FROM @ho_finish;
EXECUTE ho_stmt;
DEALLOCATE PREPARE ho_stmt;
