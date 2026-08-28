SET NAMES utf8mb4;

SET @has_performance_independent := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'eb_position'
    AND COLUMN_NAME = 'performance_independent'
);
SET @sql := IF(
  @has_performance_independent = 0,
  'ALTER TABLE `eb_position` ADD COLUMN `performance_independent` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''仅记录岗位业绩独立核算标记'' AFTER `allow_store_select`',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `eb_position`
SET `performance_independent` = CASE WHEN `performance_independent` = 1 THEN 1 ELSE 0 END
WHERE `performance_independent` IS NOT NULL;

INSERT INTO `eb_database_upgrade_log`
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260828-002-job-position-performance-independent',
  '岗位业绩独立核算标记',
  '2026-08-28-岗位业绩独立核算标记/02-正式升级.sql',
  '', '', NOW(), 'codex-local',
  '新增岗位业绩独立核算记录字段，不接入其他业务逻辑'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key` = '20260828-002-job-position-performance-independent'
);
