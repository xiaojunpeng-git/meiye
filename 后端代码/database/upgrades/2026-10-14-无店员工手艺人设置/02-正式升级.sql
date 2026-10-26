-- Only add employee defaults. Never backfill or overwrite store assignments.
SET NAMES utf8mb4;
SET @craft_db := DATABASE();
SELECT COUNT(*) INTO @craft_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @craft_db AND TABLE_NAME = 'eb_employee' AND COLUMN_NAME = 'default_craftsman_performance_type';
SET @craft_sql := IF(@craft_exists = 0,
  'ALTER TABLE eb_employee ADD COLUMN default_craftsman_performance_type varchar(32) NULL DEFAULT NULL COMMENT ''No-store craftsman default, null means unset''',
  'SELECT ''DEFAULT_ALREADY_PRESENT'' AS result');
PREPARE craft_stmt FROM @craft_sql;
EXECUTE craft_stmt;
DEALLOCATE PREPARE craft_stmt;
SELECT COUNT(*) INTO @craft_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @craft_db AND TABLE_NAME = 'eb_employee' AND COLUMN_NAME = 'craftsman_setting_version';
SET @craft_sql := IF(@craft_exists = 0,
  'ALTER TABLE eb_employee ADD COLUMN craftsman_setting_version bigint unsigned NOT NULL DEFAULT 1 COMMENT ''No-store craftsman setting optimistic version''',
  'SELECT ''VERSION_ALREADY_PRESENT'' AS result');
PREPARE craft_stmt FROM @craft_sql;
EXECUTE craft_stmt;
DEALLOCATE PREPARE craft_stmt;
INSERT INTO eb_database_upgrade_log
(upgrade_key,title,task_file,sql_checksum,code_version,executed_at,executed_by,result_note)
SELECT '20261014-employee-craftsman-default','无店员工手艺人设置',
 '2026-10-14-无店员工手艺人设置/02-正式升级.sql','','',NOW(),'codex-release','Add defaults only, no assignment backfill'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key='20261014-employee-craftsman-default');
