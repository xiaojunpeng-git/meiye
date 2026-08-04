-- upgrade_key: 20260729-004-cashier-v3-checkout-settlement
-- Non-destructive partial-DDL recovery audit.
-- This file performs no CREATE, ALTER, DROP, RENAME, INSERT, UPDATE or DELETE.
SET NAMES utf8mb4;
SET @checkout_db := DATABASE();

SELECT COUNT(*) INTO @recovery_registered
FROM information_schema.TABLES t
JOIN information_schema.COLUMNS c
  ON c.TABLE_SCHEMA=t.TABLE_SCHEMA AND c.TABLE_NAME=t.TABLE_NAME
WHERE t.TABLE_SCHEMA=@checkout_db AND t.TABLE_NAME='eb_database_upgrade_log'
  AND c.COLUMN_NAME='upgrade_key';

SET @recovery_upgrade_key_used := 0;
SET @recovery_log_sql := IF(
  @recovery_registered=1,
  'SELECT COUNT(*) INTO @recovery_upgrade_key_used FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-004-cashier-v3-checkout-settlement''',
  'SET @recovery_upgrade_key_used:=0'
);
PREPARE recovery_log_stmt FROM @recovery_log_sql;
EXECUTE recovery_log_stmt;
DEALLOCATE PREPARE recovery_log_stmt;

SELECT COUNT(*) INTO @recovery_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME IN (
  'eb_cashier_v3_checkout_request',
  'eb_cashier_v3_checkout_line_draft',
  'eb_cashier_v3_checkout_payment_draft'
);

SELECT COUNT(*) INTO @recovery_table_meta_bad
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME IN (
  'eb_cashier_v3_checkout_request',
  'eb_cashier_v3_checkout_line_draft',
  'eb_cashier_v3_checkout_payment_draft'
) AND (ENGINE<>'InnoDB' OR TABLE_COLLATION<>'utf8mb4_general_ci');

SELECT
  COALESCE(SUM(TABLE_NAME='eb_cashier_v3_checkout_request'),0) AS request_columns,
  COALESCE(SUM(TABLE_NAME='eb_cashier_v3_checkout_line_draft'),0) AS line_columns,
  COALESCE(SUM(TABLE_NAME='eb_cashier_v3_checkout_payment_draft'),0) AS payment_columns
INTO @recovery_request_columns,@recovery_line_columns,@recovery_payment_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@checkout_db AND TABLE_NAME IN (
  'eb_cashier_v3_checkout_request',
  'eb_cashier_v3_checkout_line_draft',
  'eb_cashier_v3_checkout_payment_draft'
);

SET @recovery_request_rows := 0;
SET @recovery_line_rows := 0;
SET @recovery_payment_rows := 0;

SET @recovery_request_sql := IF(
  @recovery_request_columns>0,
  'SELECT COUNT(*) INTO @recovery_request_rows FROM eb_cashier_v3_checkout_request',
  'SET @recovery_request_rows:=0'
);
PREPARE recovery_request_stmt FROM @recovery_request_sql;
EXECUTE recovery_request_stmt;
DEALLOCATE PREPARE recovery_request_stmt;

SET @recovery_line_sql := IF(
  @recovery_line_columns>0,
  'SELECT COUNT(*) INTO @recovery_line_rows FROM eb_cashier_v3_checkout_line_draft',
  'SET @recovery_line_rows:=0'
);
PREPARE recovery_line_stmt FROM @recovery_line_sql;
EXECUTE recovery_line_stmt;
DEALLOCATE PREPARE recovery_line_stmt;

SET @recovery_payment_sql := IF(
  @recovery_payment_columns>0,
  'SELECT COUNT(*) INTO @recovery_payment_rows FROM eb_cashier_v3_checkout_payment_draft',
  'SET @recovery_payment_rows:=0'
);
PREPARE recovery_payment_stmt FROM @recovery_payment_sql;
EXECUTE recovery_payment_stmt;
DEALLOCATE PREPARE recovery_payment_stmt;

SET @recovery_expected_column_count_bad :=
    IF(@recovery_request_columns IN (0,45),0,1)
  + IF(@recovery_line_columns IN (0,31),0,1)
  + IF(@recovery_payment_columns IN (0,26),0,1);
SET @recovery_total_rows :=
  @recovery_request_rows + @recovery_line_rows + @recovery_payment_rows;
SET @recovery_blocked :=
    IF(@recovery_registered=1,0,1)
  + IF(@recovery_upgrade_key_used=0,0,1)
  + @recovery_table_meta_bad
  + @recovery_expected_column_count_bad
  + IF(@recovery_total_rows=0,0,1);

SELECT
  @checkout_db AS db_name,
  VERSION() AS mysql_version,
  @recovery_upgrade_key_used AS upgrade_key_used,
  @recovery_table_count AS existing_target_table_count,
  @recovery_request_columns AS request_column_count,
  @recovery_line_columns AS line_column_count,
  @recovery_payment_columns AS payment_column_count,
  @recovery_request_rows AS request_exact_row_count,
  @recovery_line_rows AS line_exact_row_count,
  @recovery_payment_rows AS payment_exact_row_count,
  @recovery_table_meta_bad AS table_meta_bad,
  @recovery_expected_column_count_bad AS column_count_bad,
  @recovery_blocked AS recovery_blocked;

SET @recovery_result := IF(
  @recovery_blocked>0,
  'RECOVERY_BLOCKED_NO_MUTATION',
  IF(@recovery_table_count=0,
    'NO_PARTIAL_DDL_NO_MUTATION',
    IF(@recovery_table_count<3,
      'EMPTY_PARTIAL_DDL_REVIEW_REQUIRED_NO_MUTATION',
      'EMPTY_FULL_UNREGISTERED_DDL_REVIEW_REQUIRED_NO_MUTATION'
    )
  )
);
SELECT @recovery_result AS recovery_audit_result;

-- Even a non-blocked result is only evidence for a human-reviewed recovery
-- task. It is never authorization to drop or replay a table.
