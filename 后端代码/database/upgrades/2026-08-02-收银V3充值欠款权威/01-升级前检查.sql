-- upgrade_key: 20260802-001-cashier-v3-recharge-debt-authority
-- Read-only precheck; MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rcd_db := DATABASE();

SELECT COUNT(*) INTO @rcd_log_ready
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rcd_db AND TABLE_NAME='eb_database_upgrade_log' AND COLUMN_NAME='upgrade_key';
SELECT COUNT(*) INTO @rcd_debt_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rcd_db AND TABLE_NAME='eb_store_debt' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rcd_recharge_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rcd_db AND TABLE_NAME='eb_user_recharge' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rcd_existing
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rcd_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_authority';
SELECT COUNT(*) INTO @rcd_existing_shape_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rcd_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_authority'
  AND COLUMN_NAME IN ('debt_id','recharge_id','command_idempotency_key','authority_fingerprint');

SET @rcd_failures := IF(@rcd_log_ready=1,0,1)
  + IF(@rcd_debt_ready=1,0,1)
  + IF(@rcd_recharge_ready=1,0,1)
  + IF(@rcd_existing IN (0,1),0,1)
  + IF(@rcd_existing=0 OR @rcd_existing_shape_ok=4,0,1);
SELECT @rcd_log_ready AS upgrade_log_ready,@rcd_debt_ready AS store_debt_ready,
  @rcd_recharge_ready AS user_recharge_ready,@rcd_existing AS existing_table_count,
  @rcd_existing_shape_ok AS existing_shape_column_count,@rcd_failures AS precheck_failure_count;
SET @rcd_abort := IF(@rcd_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_RECHARGE_DEBT_PRECHECK_FAILED');
PREPARE rcd_stmt FROM @rcd_abort; EXECUTE rcd_stmt; DEALLOCATE PREPARE rcd_stmt;
