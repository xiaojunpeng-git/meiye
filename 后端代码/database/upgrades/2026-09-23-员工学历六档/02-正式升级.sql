-- upgrade_key: 20260923-001-employee-education-six-levels
-- MySQL 5.6+ compatible; both ALTER operations are repeatable and leave existing rows untouched.
SET NAMES utf8mb4;
SET @education_db := DATABASE();

SELECT COUNT(*) INTO @education_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @education_db AND TABLE_NAME = 'eb_employee' AND COLUMN_NAME = 'education';
SET @education_sql := IF(@education_column = 0,
  'ALTER TABLE `eb_employee` ADD COLUMN `education` varchar(16) NOT NULL DEFAULT '''' COMMENT ''员工学历六档；空串为未填写''',
  'SELECT ''EDUCATION_COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE education_stmt FROM @education_sql;
EXECUTE education_stmt;
DEALLOCATE PREPARE education_stmt;

SELECT COUNT(*) INTO @education_version_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @education_db AND TABLE_NAME = 'eb_employee' AND COLUMN_NAME = 'education_version';
SET @education_sql := IF(@education_version_column = 0,
  'ALTER TABLE `eb_employee` ADD COLUMN `education_version` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT ''员工学历乐观锁版本''',
  'SELECT ''EDUCATION_VERSION_COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE education_stmt FROM @education_sql;
EXECUTE education_stmt;
DEALLOCATE PREPARE education_stmt;

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260923-001-employee-education-six-levels','员工学历六档',
  '2026-09-23-员工学历六档/02-正式升级.sql','','',NOW(),'codex-release',
  'employee education and optimistic version; no historical backfill'
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key = '20260923-001-employee-education-six-levels'
);
SELECT 'APPLY_OK' AS apply_result;
