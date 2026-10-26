-- upgrade_key: 20260729-004-cashier-v3-checkout-settlement
-- Read-only precheck. Explicit safe gate: every target table must be absent.
SET NAMES utf8mb4;
SET @checkout_db := DATABASE();
SET @checkout_failures := 0;

SELECT COUNT(*) INTO @checkout_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @checkout_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

SET @checkout_upgrade_key_used := 0;
SET @checkout_log_sql := IF(
  @checkout_upgrade_log_exists=1 AND @checkout_upgrade_key_column=1,
  'SELECT COUNT(*) INTO @checkout_upgrade_key_used FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-004-cashier-v3-checkout-settlement''',
  'SET @checkout_upgrade_key_used:=0'
);
PREPARE checkout_log_stmt FROM @checkout_log_sql;
EXECUTE checkout_log_stmt;
DEALLOCATE PREPARE checkout_log_stmt;

SELECT COUNT(*) INTO @checkout_target_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME IN (
  'eb_cashier_v3_checkout_request',
  'eb_cashier_v3_checkout_line_draft',
  'eb_cashier_v3_checkout_payment_draft'
);

-- Any target table means either a partial DDL or an unregistered full apply.
-- Both cases stop here. No table is dropped or guessed by this package.
SET @checkout_partial_ddl_safe_gate := IF(@checkout_target_table_count=0,0,1);
SET @checkout_failures := @checkout_failures
  + IF(@checkout_upgrade_log_exists=1,0,1)
  + IF(@checkout_upgrade_key_column=1,0,1)
  + IF(@checkout_upgrade_key_used=0,0,1)
  + @checkout_partial_ddl_safe_gate;

SELECT
  @checkout_db AS db_name,
  VERSION() AS mysql_version,
  @checkout_upgrade_log_exists AS upgrade_log_exists,
  @checkout_upgrade_key_used AS upgrade_key_used,
  @checkout_target_table_count AS target_table_count,
  @checkout_partial_ddl_safe_gate AS partial_ddl_safe_gate_blocked,
  @checkout_failures AS precheck_failure_count;

SET @checkout_finish_sql := IF(
  @checkout_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result, ''PARTIAL_DDL_SAFE_GATE_OK'' AS recovery_gate',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''cashier v3 checkout settlement precheck failed; inspect partial DDL safely'''
);
PREPARE checkout_finish_stmt FROM @checkout_finish_sql;
EXECUTE checkout_finish_stmt;
DEALLOCATE PREPARE checkout_finish_stmt;
