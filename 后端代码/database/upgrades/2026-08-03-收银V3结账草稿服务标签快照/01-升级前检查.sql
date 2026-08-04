-- upgrade_key: 20260803-004-cashier-v3-checkout-draft-service-tags-v1
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @cdst_db := DATABASE();
SET @cdst_failures := 0;

SELECT COUNT(*) INTO @cdst_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cdst_db AND TABLE_NAME='eb_database_upgrade_log';

SET @cdst_registered := 0;
SET @cdst_registered_sql := IF(
  @cdst_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @cdst_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260803-004-cashier-v3-checkout-draft-service-tags-v1''',
  'SELECT 0 INTO @cdst_registered'
);
PREPARE cdst_registered_stmt FROM @cdst_registered_sql;
EXECUTE cdst_registered_stmt;
DEALLOCATE PREPARE cdst_registered_stmt;

SELECT COUNT(*) INTO @cdst_target_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cdst_db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @cdst_existing_tag_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cdst_db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft'
  AND COLUMN_NAME IN ('service_object','is_experience');

SET @cdst_failures := @cdst_failures
  + IF(@cdst_upgrade_log_exists=1,0,1)
  + IF(@cdst_registered=0,0,1)
  + IF(@cdst_target_table=1,0,1)
  + IF(@cdst_existing_tag_columns=0,0,1);

SELECT @cdst_db AS db_name, @cdst_registered AS already_registered,
  @cdst_target_table AS target_table_count,
  @cdst_existing_tag_columns AS existing_service_tag_column_count,
  @cdst_failures AS precheck_failure_count;

SET @cdst_finish_sql := IF(
  @cdst_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_CHECKOUT_DRAFT_SERVICE_TAGS_PRECHECK_FAILED'
);
PREPARE cdst_finish_stmt FROM @cdst_finish_sql;
EXECUTE cdst_finish_stmt;
DEALLOCATE PREPARE cdst_finish_stmt;
