-- upgrade_key: 20260729-013-mobile-auth-canonical-identity
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @maci_db := DATABASE();
SET @maci_failures := 0;

SELECT COUNT(*) INTO @maci_log_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_database_upgrade_log' AND ENGINE='InnoDB';

SET @maci_registered := 0;
SET @maci_registered_sql := IF(@maci_log_table=1,
  'SELECT COUNT(*) INTO @maci_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-013-mobile-auth-canonical-identity''',
  'SELECT 0 INTO @maci_registered');
PREPARE maci_registered_stmt FROM @maci_registered_sql;
EXECUTE maci_registered_stmt;
DEALLOCATE PREPARE maci_registered_stmt;

SELECT COUNT(*) INTO @maci_prereq_log_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_database_upgrade_log';

SET @maci_prereq_registered := 0;
SET @maci_prereq_sql := IF(@maci_prereq_log_count=1,
  'SELECT COUNT(*) INTO @maci_prereq_registered FROM eb_database_upgrade_log WHERE upgrade_key IN (''20260719-009-employee-org-leader'',''20260722-027-employee-org-staff-i1'',''20260722-028-employee-auth-center-i2'',''20260722-029-employee-auth-i2-schema-strict'',''20260722-031-job-position-data-scope-i2'',''20260723-033-org-auth-version-merchant'',''20260723-035-hq-store-role-template'',''20260724-001-org-identity-final'',''20260727-001-cashier-v3-command-idem'',''20260728-003-cashier-v3-event-outbox'',''20260728-002-cashier-v3-member-consistency'')',
  'SELECT 0 INTO @maci_prereq_registered');
PREPARE maci_prereq_stmt FROM @maci_prereq_sql;
EXECUTE maci_prereq_stmt;
DEALLOCATE PREPARE maci_prereq_stmt;

SELECT COUNT(*) INTO @maci_user_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_user'
  AND COLUMN_NAME IN ('uid','phone','is_del') AND IS_NULLABLE='NO';

SELECT COUNT(*) INTO @maci_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@maci_db
  AND TABLE_NAME IN ('eb_user_phone_identity','eb_user_phone_identity_audit');

SELECT COUNT(*) INTO @maci_existing_identity_exact
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_user_phone_identity'
  AND ((COLUMN_NAME='id' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%' AND EXTRA LIKE '%auto_increment%')
    OR (COLUMN_NAME='phone_digest' AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH=64 AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
    OR (COLUMN_NAME='bound_uid' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='YES')
    OR (COLUMN_NAME='state' AND DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH=32 AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND COLUMN_DEFAULT='RESERVED')
    OR (COLUMN_NAME='identity_version' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1')
    OR (COLUMN_NAME IN ('bound_at','released_at','created_at','updated_at') AND DATA_TYPE='int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0'));

SELECT COUNT(*) INTO @maci_existing_audit_exact
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_user_phone_identity_audit'
  AND COLUMN_NAME IN ('id','identity_id','phone_digest','before_uid','after_uid','before_state','after_state','before_version','after_version','action','operator_type','operator_id','source','request_id','occurred_at','recorded_at');

SELECT COUNT(*) INTO @maci_valid_phone_rows
FROM eb_user
WHERE phone REGEXP '^1[3-9][0-9]{9}$';

SELECT COUNT(*) INTO @maci_duplicate_phone_groups
FROM (SELECT phone FROM eb_user WHERE phone REGEXP '^1[3-9][0-9]{9}$' GROUP BY phone HAVING COUNT(*)>1) maci_duplicates;

SELECT COUNT(*) INTO @maci_live_duplicate_phone_groups
FROM (SELECT phone FROM eb_user WHERE is_del=0 AND phone REGEXP '^1[3-9][0-9]{9}$' GROUP BY phone HAVING COUNT(*)>1) maci_live_duplicates;

SET @maci_failures := @maci_failures
  + IF(@maci_log_table=1,0,1)
  + IF(@maci_registered=0,0,1)
  + IF(@maci_prereq_registered=11,0,1)
  + IF(@maci_user_columns=3,0,1)
  + IF(@maci_target_tables IN (0,2),0,1)
  + IF(@maci_target_tables=0 OR @maci_existing_identity_exact=9,0,1)
  + IF(@maci_target_tables=0 OR @maci_existing_audit_exact=16,0,1);

SELECT @maci_db AS db_name, VERSION() AS mysql_version,
  @maci_registered AS already_registered,
  @maci_prereq_registered AS prerequisite_upgrade_count,
  @maci_target_tables AS existing_target_table_count,
  @maci_valid_phone_rows AS valid_phone_member_count,
  @maci_duplicate_phone_groups AS historical_duplicate_phone_group_count,
  @maci_live_duplicate_phone_groups AS live_duplicate_phone_group_count,
  @maci_failures AS precheck_failure_count;

SET @maci_finish_sql := IF(@maci_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_MOBILE_AUTH_CANONICAL_IDENTITY_PRECHECK_FAILED');
PREPARE maci_finish_stmt FROM @maci_finish_sql;
EXECUTE maci_finish_stmt;
DEALLOCATE PREPARE maci_finish_stmt;
