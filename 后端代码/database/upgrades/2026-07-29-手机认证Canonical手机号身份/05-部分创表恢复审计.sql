-- upgrade_key: 20260729-013-mobile-auth-canonical-identity
-- Read-only interrupted-DDL audit. No DROP, DELETE or repair statements.
SET NAMES utf8mb4;
SET @maci_db := DATABASE();

SELECT COUNT(*) INTO @maci_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME IN ('eb_user_phone_identity','eb_user_phone_identity_audit');

SET @maci_registered := 0;
SET @maci_log_sql := IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_database_upgrade_log'),
  'SELECT COUNT(*) INTO @maci_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-013-mobile-auth-canonical-identity''',
  'SELECT 0 INTO @maci_registered');
PREPARE maci_log_stmt FROM @maci_log_sql;
EXECUTE maci_log_stmt;
DEALLOCATE PREPARE maci_log_stmt;

SET @maci_identity_rows := 0;
SET @maci_has_identity := IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_user_phone_identity'),
  1, 0);
SET @maci_identity_rows_sql := IF(@maci_has_identity=1,
  'SELECT COUNT(*) INTO @maci_identity_rows FROM eb_user_phone_identity',
  'SELECT 0 INTO @maci_identity_rows');
PREPARE maci_identity_rows_stmt FROM @maci_identity_rows_sql;
EXECUTE maci_identity_rows_stmt;
DEALLOCATE PREPARE maci_identity_rows_stmt;

SET @maci_audit_rows := 0;
SET @maci_audit_rows_sql := IF(@maci_tables=2,
  'SELECT COUNT(*) INTO @maci_audit_rows FROM eb_user_phone_identity_audit',
  'SELECT 0 INTO @maci_audit_rows');
PREPARE maci_audit_rows_stmt FROM @maci_audit_rows_sql;
EXECUTE maci_audit_rows_stmt;
DEALLOCATE PREPARE maci_audit_rows_stmt;

SELECT @maci_tables AS target_table_count,
  @maci_registered AS upgrade_registered,
  @maci_identity_rows AS identity_row_count,
  @maci_audit_rows AS audit_row_count;

SET @maci_finish_sql := CASE
  WHEN @maci_tables=0 THEN 'SELECT * FROM STOP_MOBILE_AUTH_CANONICAL_IDENTITY_NO_PARTIAL_DDL'
  WHEN @maci_tables=2 THEN 'SELECT * FROM STOP_MOBILE_AUTH_CANONICAL_IDENTITY_FULL_INSTALL'
  WHEN @maci_registered<>0 THEN 'SELECT * FROM STOP_MOBILE_AUTH_CANONICAL_IDENTITY_ALREADY_REGISTERED'
  WHEN @maci_identity_rows<>0 OR @maci_audit_rows<>0 THEN 'SELECT * FROM STOP_MOBILE_AUTH_CANONICAL_IDENTITY_NONEMPTY_PARTIAL'
  ELSE 'SELECT ''PARTIAL_DDL_RECOVERY_READY'' AS recovery_result'
END;
PREPARE maci_finish_stmt FROM @maci_finish_sql;
EXECUTE maci_finish_stmt;
DEALLOCATE PREPARE maci_finish_stmt;
