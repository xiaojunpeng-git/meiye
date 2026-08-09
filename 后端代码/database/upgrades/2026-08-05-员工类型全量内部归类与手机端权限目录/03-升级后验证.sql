-- upgrade_key: 20260805-002-employee-type-mobile-catalog-normalization
-- Read-only postcheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @etmc_catalog_rules := '401100,401001,401002,401003,401004,401005,401006,401007,401008,401200,401300';
SET @etmc_failures := 0;

SELECT COUNT(*) INTO @etmc_remaining_active_unclassified
FROM eb_employee
WHERE status=1
  AND is_del=0
  AND employment_type_code IS NULL
  AND employment_type_version=0;

SELECT COUNT(*) INTO @etmc_invalid_type_rows
FROM eb_employee
WHERE NOT (
  (employment_type_code IS NULL AND employment_type_version=0)
  OR
  (employment_type_code IN ('internal','partner','outsourced') AND employment_type_version>0)
);

SELECT COUNT(*) INTO @etmc_invalid_migration_audit_rows
FROM eb_employee_change_log audit
LEFT JOIN eb_employee employee ON employee.id=audit.employee_id
WHERE audit.action='employee_employment_type_migration_internal'
  AND audit.request_id='20260805-002-employee-type-mobile-catalog-normalization'
  AND (
    employee.id IS NULL
    OR employee.employment_type_code<>'internal'
    OR employee.employment_type_version<1
  );

SELECT COUNT(*) INTO @etmc_position_has_is_del
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_position'
  AND COLUMN_NAME='is_del';

SET @etmc_position_filter := IF(@etmc_position_has_is_del=1, ' AND position.is_del=0', '');
SET @etmc_position_mobile_off_count := 0;
SET @etmc_position_mobile_off_sql := CONCAT(
  'SELECT COUNT(*) INTO @etmc_position_mobile_off_count FROM eb_position position WHERE COALESCE(position.use_mobile,0)<>1',
  @etmc_position_filter
);
PREPARE etmc_position_mobile_off_stmt FROM @etmc_position_mobile_off_sql;
EXECUTE etmc_position_mobile_off_stmt;
DEALLOCATE PREPARE etmc_position_mobile_off_stmt;

SET @etmc_mobile_rule_mismatch_count := 0;
SET @etmc_mobile_rule_mismatch_sql := CONCAT(
  'SELECT COUNT(*) INTO @etmc_mobile_rule_mismatch_count FROM eb_position position ',
  'LEFT JOIN eb_job_position_channel_rule rule_row ON rule_row.position_id=position.id AND rule_row.channel=''mobile'' ',
  'WHERE (rule_row.position_id IS NULL OR rule_row.status<>1 OR COALESCE(rule_row.rules,'''')<>@etmc_catalog_rules)',
  @etmc_position_filter
);
PREPARE etmc_mobile_rule_mismatch_stmt FROM @etmc_mobile_rule_mismatch_sql;
EXECUTE etmc_mobile_rule_mismatch_stmt;
DEALLOCATE PREPARE etmc_mobile_rule_mismatch_stmt;

SELECT COUNT(*) INTO @etmc_migration_audit_count
FROM eb_employee_change_log
WHERE action='employee_employment_type_migration_internal'
  AND request_id='20260805-002-employee-type-mobile-catalog-normalization';

SET @etmc_failures := @etmc_failures
  + IF(@etmc_remaining_active_unclassified=0,0,1)
  + IF(@etmc_invalid_type_rows=0,0,1)
  + IF(@etmc_invalid_migration_audit_rows=0,0,1)
  + IF(@etmc_position_mobile_off_count=0,0,1)
  + IF(@etmc_mobile_rule_mismatch_count=0,0,1);

SELECT
  @etmc_remaining_active_unclassified AS remaining_active_unclassified_count,
  @etmc_invalid_type_rows AS invalid_employee_type_count,
  @etmc_invalid_migration_audit_rows AS invalid_migration_audit_count,
  @etmc_migration_audit_count AS migration_internal_audit_count,
  @etmc_position_mobile_off_count AS mobile_disabled_position_count,
  @etmc_mobile_rule_mismatch_count AS mobile_catalog_rule_mismatch_count,
  @etmc_catalog_rules AS mobile_catalog_rules,
  @etmc_failures AS postcheck_failure_count;

SET @etmc_finish_sql := IF(
  @etmc_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_EMPLOYEE_TYPE_MOBILE_CATALOG_POSTCHECK_FAILED'
);
PREPARE etmc_finish_stmt FROM @etmc_finish_sql;
EXECUTE etmc_finish_stmt;
DEALLOCATE PREPARE etmc_finish_stmt;
