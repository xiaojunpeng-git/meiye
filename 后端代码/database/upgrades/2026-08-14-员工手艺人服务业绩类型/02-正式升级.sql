-- upgrade_key: 20260814-003-employee-craftsman-performance-type
-- MySQL 5.6 compatible and idempotent.
SET NAMES utf8mb4;
SET @cpt_db := DATABASE();
SELECT COUNT(*) INTO @cpt_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cpt_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='craftsman_performance_type';
SET @cpt_sql := IF(@cpt_exists=0,
  'ALTER TABLE `eb_system_store_staff` ADD COLUMN `craftsman_performance_type` varchar(20) NOT NULL DEFAULT ''commission'' COMMENT ''手艺人服务业绩类型 commission业绩比例 labor手工 commission_labor两者'' AFTER `cashier_craftsman_enabled`',
  'SELECT ''CRAFTSMAN_PERFORMANCE_TYPE_ALREADY_PRESENT'' AS apply_result');
PREPARE cpt_stmt FROM @cpt_sql;
EXECUTE cpt_stmt;
DEALLOCATE PREPARE cpt_stmt;
UPDATE `eb_system_store_staff`
SET `craftsman_performance_type`='commission'
WHERE `craftsman_performance_type` IS NULL OR `craftsman_performance_type`=''
   OR `craftsman_performance_type` NOT IN ('commission','labor','commission_labor');
SELECT 'APPLY_OK' AS apply_result;
