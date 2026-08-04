-- upgrade_key: 20260804-001-cashier-v3-repeat-payment-method-drafts
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rpm_db := DATABASE();
SET @rpm_failures := 0;

SELECT COUNT(*) INTO @rpm_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rpm_db AND TABLE_NAME='eb_database_upgrade_log';

SET @rpm_registered := 0;
SET @rpm_registered_sql := IF(
  @rpm_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @rpm_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260804-001-cashier-v3-repeat-payment-method-drafts''',
  'SELECT 0 INTO @rpm_registered'
);
PREPARE rpm_registered_stmt FROM @rpm_registered_sql;
EXECUTE rpm_registered_stmt;
DEALLOCATE PREPARE rpm_registered_stmt;

SELECT COUNT(*) INTO @rpm_target_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rpm_db
  AND TABLE_NAME='eb_cashier_v3_checkout_payment_draft'
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @rpm_legacy_index
FROM (
  SELECT INDEX_NAME, MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@rpm_db AND TABLE_NAME='eb_cashier_v3_checkout_payment_draft'
    AND INDEX_NAME='uk_request_method'
  GROUP BY INDEX_NAME
  HAVING non_unique=0 AND index_columns='request_id,payment_method'
) rpm_legacy_indexes;

SELECT COUNT(*) INTO @rpm_authority_index
FROM (
  SELECT INDEX_NAME, MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@rpm_db AND TABLE_NAME='eb_cashier_v3_checkout_payment_draft'
    AND INDEX_NAME='uk_request_payment_authority'
  GROUP BY INDEX_NAME
  HAVING non_unique=0 AND index_columns='request_id,payment_authority_key'
) rpm_authority_indexes;

SET @rpm_failures := @rpm_failures
  + IF(@rpm_upgrade_log_exists=1,0,1)
  + IF(@rpm_registered=0,0,1)
  + IF(@rpm_target_table=1,0,1)
  + IF(@rpm_legacy_index=1,0,1)
  + IF(@rpm_authority_index=1,0,1);

SELECT @rpm_db AS db_name, @rpm_registered AS already_registered,
  @rpm_target_table AS target_table_count,
  @rpm_legacy_index AS legacy_request_method_index_count,
  @rpm_authority_index AS request_authority_index_count,
  @rpm_failures AS precheck_failure_count;

SET @rpm_finish_sql := IF(
  @rpm_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_REPEAT_PAYMENT_METHOD_PRECHECK_FAILED'
);
PREPARE rpm_finish_stmt FROM @rpm_finish_sql;
EXECUTE rpm_finish_stmt;
DEALLOCATE PREPARE rpm_finish_stmt;
