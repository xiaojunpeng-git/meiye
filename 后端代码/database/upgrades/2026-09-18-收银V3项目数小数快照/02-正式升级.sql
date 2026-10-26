-- upgrade_key: 20260918-001-cashier-v3-project-count-decimal
SET NAMES utf8mb4;
SET @db := DATABASE();

-- 保留旧 half_units 的历史语义；新字段只记录本次明确输入的小数项目数。
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_performance_fact'
     AND COLUMN_NAME='project_count_decimal')=0,
  'ALTER TABLE `eb_cashier_v3_performance_fact` ADD COLUMN `project_count_decimal` decimal(20,6) NULL DEFAULT NULL AFTER `project_count_half_units`, ADD KEY `idx_service_employee_project_decimal` (`tenant_id`,`source_line_id`,`employee_id`,`project_count_decimal`,`id`)',
  'SELECT ''project_count_decimal_already_present'' AS apply_result'
);
PREPARE project_count_decimal_stmt FROM @sql;
EXECUTE project_count_decimal_stmt;
DEALLOCATE PREPARE project_count_decimal_stmt;

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260918-001-cashier-v3-project-count-decimal',
  '收银 V3 项目数小数快照',
  'database/upgrades/2026-09-18-收银V3项目数小数快照/02-正式升级.sql',
  '',
  'WORKTREE',
  NOW(),
  'Codex',
  '仅新增项目数精确小数快照列与索引；不回填、不更新、不删除历史业绩事实'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260918-001-cashier-v3-project-count-decimal'
);

SELECT 'APPLY_OK' AS apply_result;
