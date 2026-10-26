-- upgrade_key: 20260805-002-employee-type-mobile-catalog-normalization
-- MySQL 5.6.51 compatible. Replay-safe data and job-policy normalization.
SET NAMES utf8mb4;
SET @etmc_catalog_rules := '401100,401001,401002,401003,401004,401005,401006,401007,401008,401200,401300';
SET @etmc_now := UNIX_TIMESTAMP();

DROP TEMPORARY TABLE IF EXISTS tmp_etmc_internal_employee;
CREATE TEMPORARY TABLE tmp_etmc_internal_employee (
  employee_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY (employee_id)
) ENGINE=InnoDB;

START TRANSACTION;

INSERT INTO tmp_etmc_internal_employee (employee_id)
SELECT employee.id
FROM eb_employee employee
WHERE employee.status=1
  AND employee.is_del=0
  AND employee.employment_type_code IS NULL
  AND employee.employment_type_version=0
FOR UPDATE;

INSERT INTO eb_employee_change_log
  (employee_id,action,target_type,target_id,source,before_data,after_data,reason,
   operator_type,operator_id,operator_name,operator_ip,request_id,add_time)
SELECT
  target.employee_id,
  'employee_employment_type_migration_internal',
  'employee_employment_type',
  target.employee_id,
  'migration',
  '{"employment_type_code":null,"employment_type_version":0}',
  '{"employment_type_code":"internal","employment_type_version":1}',
  '在职未分类员工统一归类为内部员工',
  'system',
  0,
  'database_upgrade',
  '',
  '20260805-002-employee-type-mobile-catalog-normalization',
  @etmc_now
FROM tmp_etmc_internal_employee target
INNER JOIN eb_employee employee ON employee.id=target.employee_id
WHERE employee.status=1
  AND employee.is_del=0
  AND employee.employment_type_code IS NULL
  AND employee.employment_type_version=0;

UPDATE eb_employee employee
INNER JOIN tmp_etmc_internal_employee target ON target.employee_id=employee.id
SET employee.employment_type_code='internal',
    employee.employment_type_version=1,
    employee.update_time=@etmc_now
WHERE employee.status=1
  AND employee.is_del=0
  AND employee.employment_type_code IS NULL
  AND employee.employment_type_version=0;

SELECT COUNT(*) INTO @etmc_position_has_is_del
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_position'
  AND COLUMN_NAME='is_del';

SET @etmc_position_filter := IF(@etmc_position_has_is_del=1, ' AND position.is_del=0', '');
SET @etmc_enable_position_sql := CONCAT(
  'UPDATE eb_position position SET position.use_mobile=1, position.version=position.version+1, position.update_time=', @etmc_now,
  ' WHERE COALESCE(position.use_mobile,0)<>1', @etmc_position_filter
);
PREPARE etmc_enable_position_stmt FROM @etmc_enable_position_sql;
EXECUTE etmc_enable_position_stmt;
DEALLOCATE PREPARE etmc_enable_position_stmt;

SET @etmc_upsert_rules_sql := CONCAT(
  'INSERT INTO eb_job_position_channel_rule (position_id,channel,rules,status,version,add_time,update_time) ',
  'SELECT position.id,''mobile'',@etmc_catalog_rules,1,1,', @etmc_now, ',', @etmc_now,
  ' FROM eb_position position WHERE 1=1', @etmc_position_filter,
  ' ON DUPLICATE KEY UPDATE ',
  'version=IF(eb_job_position_channel_rule.status<>1 OR COALESCE(eb_job_position_channel_rule.rules,'''')<>VALUES(rules),eb_job_position_channel_rule.version+1,eb_job_position_channel_rule.version),',
  'update_time=IF(eb_job_position_channel_rule.status<>1 OR COALESCE(eb_job_position_channel_rule.rules,'''')<>VALUES(rules),VALUES(update_time),eb_job_position_channel_rule.update_time),',
  'rules=VALUES(rules),status=VALUES(status)'
);
PREPARE etmc_upsert_rules_stmt FROM @etmc_upsert_rules_sql;
EXECUTE etmc_upsert_rules_stmt;
DEALLOCATE PREPARE etmc_upsert_rules_stmt;

COMMIT;

SELECT COUNT(*) AS selected_internal_employee_count
FROM tmp_etmc_internal_employee;
DROP TEMPORARY TABLE IF EXISTS tmp_etmc_internal_employee;
SELECT 'APPLY_OK' AS apply_result;
