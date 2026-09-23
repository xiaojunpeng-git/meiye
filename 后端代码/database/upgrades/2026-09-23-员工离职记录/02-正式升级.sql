-- upgrade_key: 20260923-002-employee-departure-records
-- MySQL 5.6+ compatible; repeatable and does not synthesize historical departure dates.
SET NAMES utf8mb4;
SET @departure_db := DATABASE();

SELECT COUNT(*) INTO @status_version_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @departure_db AND TABLE_NAME = 'eb_employee' AND COLUMN_NAME = 'status_version';
SET @departure_sql := IF(@status_version_column = 0,
  'ALTER TABLE `eb_employee` ADD COLUMN `status_version` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT ''员工在职状态乐观锁版本'' AFTER `status`',
  'SELECT ''STATUS_VERSION_COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE departure_stmt FROM @departure_sql;
EXECUTE departure_stmt;
DEALLOCATE PREPARE departure_stmt;

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260923-002-employee-departure-records','员工离职记录与状态版本',
  '2026-09-23-员工离职记录/02-正式升级.sql','','',NOW(),'codex-local',
  'status optimistic version; departure records start prospectively; no historical backfill'
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key = '20260923-002-employee-departure-records'
);
SELECT 'APPLY_OK' AS apply_result;
