-- upgrade_key: 20260828-001-cashier-v3-sales-manager-allocation
SET NAMES utf8mb4;
SET @sm_db := DATABASE();

SET @sm_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@sm_db AND TABLE_NAME='eb_cashier_v3_sales_manager_fact' AND COLUMN_NAME='allocation_weight_numerator')=0,
  'ALTER TABLE `eb_cashier_v3_sales_manager_fact` ADD COLUMN `allocation_weight_numerator` bigint(20) unsigned NOT NULL DEFAULT 100 AFTER `sales_manager_type_snapshot`, ADD COLUMN `allocation_weight_denominator` bigint(20) unsigned NOT NULL DEFAULT 100 AFTER `allocation_weight_numerator`, ADD COLUMN `allocation_base_amount_cents` bigint(20) NOT NULL DEFAULT 0 AFTER `allocation_weight_denominator`, ADD COLUMN `amount_cents` bigint(20) NOT NULL DEFAULT 0 AFTER `allocation_base_amount_cents`',
  'SELECT ''COLUMN_ALREADY_PRESENT'' AS apply_result'
);
PREPARE sm_stmt FROM @sm_sql; EXECUTE sm_stmt; DEALLOCATE PREPARE sm_stmt;

SELECT 'APPLY_OK' AS apply_result;

INSERT INTO eb_database_upgrade_log (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260828-001-cashier-v3-sales-manager-allocation',
  '销售经理独立业绩比例快照',
  '2026-08-28-销售经理独立业绩比例快照/02-正式升级.sql',
  '', '', NOW(), 'codex', '销售经理独立比例、分配基数和金额字段升级完成'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20260828-001-cashier-v3-sales-manager-allocation'
);
