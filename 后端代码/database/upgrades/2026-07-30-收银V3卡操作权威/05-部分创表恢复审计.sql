-- upgrade_key: 20260730-021-cashier-v3-card-operation-authority-v1
-- Read-only recovery audit. It never repairs or deletes partial DDL.
SET NAMES utf8mb4;
SET @cardop_db := DATABASE();

SELECT
  TABLE_NAME,
  ENGINE,
  TABLE_COLLATION,
  TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME IN (
  'eb_cashier_v3_card_state',
  'eb_cashier_v3_card_operation',
  'eb_cashier_v3_card_operation_line'
)
ORDER BY TABLE_NAME;

SELECT
  TABLE_NAME,
  COLUMN_NAME,
  COLUMN_TYPE,
  IS_NULLABLE,
  COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME IN (
  'eb_cashier_v3_card_state',
  'eb_cashier_v3_card_operation',
  'eb_cashier_v3_card_operation_line'
)
ORDER BY TABLE_NAME,ORDINAL_POSITION;

SELECT
  TABLE_NAME,
  INDEX_NAME,
  NON_UNIQUE,
  SEQ_IN_INDEX,
  COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME IN (
  'eb_cashier_v3_card_state',
  'eb_cashier_v3_card_operation',
  'eb_cashier_v3_card_operation_line'
)
ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX;

SELECT COUNT(*) INTO @cardop_recovery_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME IN (
  'eb_cashier_v3_card_state',
  'eb_cashier_v3_card_operation',
  'eb_cashier_v3_card_operation_line'
);
SELECT COUNT(*) INTO @cardop_recovery_registered
FROM information_schema.TABLES log_table
JOIN information_schema.COLUMNS key_column
  ON key_column.TABLE_SCHEMA=log_table.TABLE_SCHEMA
 AND key_column.TABLE_NAME=log_table.TABLE_NAME
 AND key_column.COLUMN_NAME='upgrade_key'
WHERE log_table.TABLE_SCHEMA=@cardop_db
  AND log_table.TABLE_NAME='eb_database_upgrade_log';
SET @cardop_recovery_registration_sql := IF(
  @cardop_recovery_registered=1,
  'SELECT COUNT(*) INTO @cardop_recovery_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260730-021-cashier-v3-card-operation-authority-v1''',
  'SET @cardop_recovery_registered:=0'
);
PREPARE cardop_recovery_registration_stmt FROM @cardop_recovery_registration_sql;
EXECUTE cardop_recovery_registration_stmt;
DEALLOCATE PREPARE cardop_recovery_registration_stmt;

SELECT
  @cardop_recovery_table_count AS target_table_count,
  @cardop_recovery_registered AS upgrade_registered,
  CASE
    WHEN @cardop_recovery_table_count=0 THEN 'EMPTY_NO_MUTATION'
    WHEN @cardop_recovery_table_count=3 AND @cardop_recovery_registered=0 THEN 'FULL_UNREGISTERED_REVIEW_REQUIRED_NO_MUTATION'
    WHEN @cardop_recovery_table_count BETWEEN 1 AND 2 THEN 'PARTIAL_DDL_REVIEW_REQUIRED_NO_MUTATION'
    ELSE 'REGISTERED_OR_UNEXPECTED_REVIEW_REQUIRED_NO_MUTATION'
  END AS recovery_status;
