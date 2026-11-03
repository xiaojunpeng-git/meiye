-- Direct employees have no store assignment, so their identity and employment dates
-- live on the unique employee master. Existing store assignment history is untouched.
SET NAMES utf8mb4;
SET @profile_db := DATABASE();
SELECT COUNT(*) INTO @profile_id_card FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@profile_db AND TABLE_NAME='eb_employee' AND COLUMN_NAME='id_card';
SET @profile_sql := IF(@profile_id_card=0,
 'ALTER TABLE eb_employee ADD COLUMN id_card varchar(32) NOT NULL DEFAULT '''' COMMENT ''直属员工身份证''',
 'SELECT ''ID_CARD_EXISTS''');
PREPARE profile_stmt FROM @profile_sql; EXECUTE profile_stmt; DEALLOCATE PREPARE profile_stmt;

SELECT COUNT(*) INTO @profile_join_date FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@profile_db AND TABLE_NAME='eb_employee' AND COLUMN_NAME='join_date';
SET @profile_sql := IF(@profile_join_date=0,
 'ALTER TABLE eb_employee ADD COLUMN join_date date NULL DEFAULT NULL COMMENT ''直属员工入职日期''',
 'SELECT ''JOIN_DATE_EXISTS''');
PREPARE profile_stmt FROM @profile_sql; EXECUTE profile_stmt; DEALLOCATE PREPARE profile_stmt;

SELECT COUNT(*) INTO @profile_birthday_date FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@profile_db AND TABLE_NAME='eb_employee' AND COLUMN_NAME='birthday_date';
SET @profile_sql := IF(@profile_birthday_date=0,
 'ALTER TABLE eb_employee ADD COLUMN birthday_date date NULL DEFAULT NULL COMMENT ''直属员工公历生日''',
 'SELECT ''BIRTHDAY_DATE_EXISTS''');
PREPARE profile_stmt FROM @profile_sql; EXECUTE profile_stmt; DEALLOCATE PREPARE profile_stmt;

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20261103-001-rh-direct-employee-profile','直属员工个人资料',
  '20261103_rh_direct_employee_profile.sql','','',NOW(),'codex-release',
  'employee id card, join date, and birthday columns; backfill runs separately'
FROM DUAL WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key='20261103-001-rh-direct-employee-profile'
);
