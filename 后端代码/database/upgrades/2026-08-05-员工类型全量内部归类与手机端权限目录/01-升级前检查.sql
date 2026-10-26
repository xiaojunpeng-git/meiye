-- upgrade_key: 20260805-002-employee-type-mobile-catalog-normalization
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @etmc_db := DATABASE();
SET @etmc_failures := 0;
SET @etmc_catalog_rules := '401100,401001,401002,401003,401004,401005,401006,401007,401008,401200,401300';

SELECT COUNT(*) INTO @etmc_required_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@etmc_db
  AND TABLE_NAME IN (
    'eb_database_upgrade_log',
    'eb_employee',
    'eb_employee_change_log',
    'eb_position',
    'eb_job_position_channel_rule'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @etmc_required_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@etmc_db
  AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
    'eb_employee.id','eb_employee.status','eb_employee.is_del',
    'eb_employee.employment_type_code','eb_employee.employment_type_version','eb_employee.update_time',
    'eb_employee_change_log.employee_id','eb_employee_change_log.action',
    'eb_employee_change_log.target_type','eb_employee_change_log.target_id',
    'eb_employee_change_log.source','eb_employee_change_log.before_data',
    'eb_employee_change_log.after_data','eb_employee_change_log.reason',
    'eb_employee_change_log.operator_type','eb_employee_change_log.operator_id',
    'eb_employee_change_log.operator_name','eb_employee_change_log.operator_ip',
    'eb_employee_change_log.request_id','eb_employee_change_log.add_time',
    'eb_position.id','eb_position.use_mobile','eb_position.version','eb_position.update_time',
    'eb_job_position_channel_rule.position_id','eb_job_position_channel_rule.channel',
    'eb_job_position_channel_rule.rules','eb_job_position_channel_rule.status',
    'eb_job_position_channel_rule.version','eb_job_position_channel_rule.add_time',
    'eb_job_position_channel_rule.update_time'
  );

SELECT COUNT(*) INTO @etmc_position_has_is_del
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@etmc_db
  AND TABLE_NAME='eb_position'
  AND COLUMN_NAME='is_del';

SELECT COUNT(*) INTO @etmc_position_channel_unique_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@etmc_db
  AND TABLE_NAME='eb_job_position_channel_rule'
  AND INDEX_NAME='uk_position_channel'
  AND NON_UNIQUE=0
  AND COLUMN_NAME IN ('position_id','channel');

SET @etmc_registered := 0;
SET @etmc_authority_dependency := 0;
SET @etmc_log_sql := IF(
  @etmc_required_tables=5,
  'SELECT COALESCE(SUM(upgrade_key=''20260729-005-employee-employment-type-authority''),0),COALESCE(SUM(upgrade_key=''20260805-002-employee-type-mobile-catalog-normalization''),0) INTO @etmc_authority_dependency,@etmc_registered FROM eb_database_upgrade_log',
  'SELECT 0,0 INTO @etmc_authority_dependency,@etmc_registered'
);
PREPARE etmc_log_stmt FROM @etmc_log_sql;
EXECUTE etmc_log_stmt;
DEALLOCATE PREPARE etmc_log_stmt;

SET @etmc_invalid_type_rows := 0;
SET @etmc_invalid_type_sql := IF(
  @etmc_required_columns=31,
  'SELECT COUNT(*) INTO @etmc_invalid_type_rows FROM eb_employee WHERE NOT ((employment_type_code IS NULL AND employment_type_version=0) OR (employment_type_code IN (''internal'',''partner'',''outsourced'') AND employment_type_version>0))',
  'SELECT 0 INTO @etmc_invalid_type_rows'
);
PREPARE etmc_invalid_type_stmt FROM @etmc_invalid_type_sql;
EXECUTE etmc_invalid_type_stmt;
DEALLOCATE PREPARE etmc_invalid_type_stmt;

SET @etmc_employee_target_count := 0;
SET @etmc_employee_target_sql := IF(
  @etmc_required_columns=31,
  'SELECT COUNT(*) INTO @etmc_employee_target_count FROM eb_employee WHERE status=1 AND is_del=0 AND employment_type_code IS NULL AND employment_type_version=0',
  'SELECT 0 INTO @etmc_employee_target_count'
);
PREPARE etmc_employee_target_stmt FROM @etmc_employee_target_sql;
EXECUTE etmc_employee_target_stmt;
DEALLOCATE PREPARE etmc_employee_target_stmt;

SET @etmc_position_target_count := 0;
SET @etmc_position_target_sql := IF(
  @etmc_position_has_is_del=1,
  'SELECT COUNT(*) INTO @etmc_position_target_count FROM eb_position WHERE is_del=0',
  'SELECT COUNT(*) INTO @etmc_position_target_count FROM eb_position'
);
PREPARE etmc_position_target_stmt FROM @etmc_position_target_sql;
EXECUTE etmc_position_target_stmt;
DEALLOCATE PREPARE etmc_position_target_stmt;

SET @etmc_failures := @etmc_failures
  + IF(@etmc_required_tables=5,0,1)
  + IF(@etmc_required_columns=31,0,1)
  + IF(@etmc_position_has_is_del IN (0,1),0,1)
  + IF(@etmc_position_channel_unique_columns=2,0,1)
  + IF(@etmc_authority_dependency=1,0,1)
  + IF(@etmc_registered=0,0,1)
  + IF(@etmc_invalid_type_rows=0,0,1);

SELECT
  @etmc_db AS db_name,
  VERSION() AS mysql_version,
  @etmc_required_tables AS required_table_count,
  @etmc_required_columns AS required_column_count,
  @etmc_position_has_is_del AS position_is_del_column_count,
  @etmc_position_channel_unique_columns AS position_channel_unique_column_count,
  @etmc_authority_dependency AS authority_dependency_registered,
  @etmc_registered AS already_registered,
  @etmc_invalid_type_rows AS invalid_employee_type_count,
  @etmc_employee_target_count AS planned_internal_employee_count,
  @etmc_position_target_count AS planned_mobile_position_count,
  @etmc_catalog_rules AS mobile_catalog_rules,
  @etmc_failures AS precheck_failure_count;

SET @etmc_finish_sql := IF(
  @etmc_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_EMPLOYEE_TYPE_MOBILE_CATALOG_PRECHECK_FAILED'
);
PREPARE etmc_finish_stmt FROM @etmc_finish_sql;
EXECUTE etmc_finish_stmt;
DEALLOCATE PREPARE etmc_finish_stmt;
