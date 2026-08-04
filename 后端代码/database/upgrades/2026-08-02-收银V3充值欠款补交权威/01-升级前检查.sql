-- upgrade_key: 20260802-002-cashier-v3-recharge-debt-repayment-authority
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rdr_db := DATABASE();

SELECT COUNT(*) INTO @rdr_log_ready
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rdr_db AND TABLE_NAME='eb_database_upgrade_log' AND COLUMN_NAME='upgrade_key';
SELECT COUNT(*) INTO @rdr_debt_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rdr_db AND TABLE_NAME='eb_store_debt' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rdr_recharge_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rdr_db AND TABLE_NAME='eb_user_recharge' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rdr_source_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rdr_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_authority' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rdr_existing
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rdr_db AND TABLE_NAME IN (
  'eb_cashier_v3_recharge_debt_repayment',
  'eb_cashier_v3_recharge_debt_repayment_payment'
);

SET @rdr_failures := IF(@rdr_log_ready=1,0,1)
  + IF(@rdr_debt_ready=1,0,1)
  + IF(@rdr_recharge_ready=1,0,1)
  + IF(@rdr_source_ready=1,0,1)
  + IF(@rdr_existing IN (0,2),0,1);
SELECT @rdr_log_ready AS upgrade_log_ready,@rdr_debt_ready AS store_debt_ready,
  @rdr_recharge_ready AS recharge_ready,@rdr_source_ready AS source_authority_ready,
  @rdr_existing AS existing_table_count,@rdr_failures AS precheck_failure_count;
SET @rdr_abort := IF(@rdr_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_RECHARGE_DEBT_REPAYMENT_PRECHECK_FAILED');
PREPARE rdr_stmt FROM @rdr_abort; EXECUTE rdr_stmt; DEALLOCATE PREPARE rdr_stmt;
