-- upgrade_key: 20260729-002-inventory-entitlement-completion
-- Read-only precheck. A partial target schema is rejected.
SET NAMES utf8mb4;
SET @inventory_db := DATABASE();
SET @inventory_failures := 0;

SELECT COUNT(*) INTO @inventory_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@inventory_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @inventory_target_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@inventory_db AND TABLE_NAME IN (
  'eb_inventory_shortage_policy',
  'eb_inventory_stock',
  'eb_inventory_batch',
  'eb_inventory_shortage_cost_cursor',
  'eb_inventory_consumption_receipt',
  'eb_inventory_batch_consumption_fact',
  'eb_inventory_shortage_fact',
  'eb_inventory_shortage_cost_adjustment'
);

SET @inventory_failures := @inventory_failures
  + IF(@inventory_upgrade_log_exists=1,0,1)
  + IF(@inventory_target_table_count IN (0,8),0,1);

SELECT
  @inventory_db AS db_name,
  VERSION() AS mysql_version,
  @inventory_upgrade_log_exists AS upgrade_log_exists,
  @inventory_target_table_count AS target_table_count,
  @inventory_failures AS precheck_failure_count;

SET @inventory_finish_sql := IF(
  @inventory_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_INVENTORY_COMPLETION_PRECHECK_FAILED'
);
PREPARE inventory_finish_stmt FROM @inventory_finish_sql;
EXECUTE inventory_finish_stmt;
DEALLOCATE PREPARE inventory_finish_stmt;
