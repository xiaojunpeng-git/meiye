-- upgrade_key: 20260810-002-customer-lifecycle-first-course-attribution
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @clf_db := DATABASE();
SET @clf_failures := 0;

SELECT COUNT(*) INTO @clf_required_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME IN (
  'eb_cashier_v3_business_source','eb_cashier_v3_sale_fact',
  'eb_cashier_v3_payment_fact','eb_cashier_v3_balance_fact',
  'eb_cashier_v3_performance_fact','eb_cashier_v3_entitlement_service_fact',
  'eb_user','eb_database_upgrade_log'
) AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @clf_source_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME='eb_cashier_v3_business_source'
  AND COLUMN_NAME='require_secondary';

SELECT COUNT(*) INTO @clf_channel_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@clf_db
  AND TABLE_NAME IN ('eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact','eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact')
  AND COLUMN_NAME='business_source_label_snapshot';

SET @clf_channel_registered := 0;
SET @clf_channel_registration_sql := IF(
  @clf_required_tables=8,
  'SELECT COUNT(*) INTO @clf_channel_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260810-001-report-channel-fact-snapshot''',
  'SET @clf_channel_registered := 0'
);
PREPARE clf_channel_registration_stmt FROM @clf_channel_registration_sql;
EXECUTE clf_channel_registration_stmt;
DEALLOCATE PREPARE clf_channel_registration_stmt;

SET @clf_failures := IF(@clf_required_tables=8,0,1)
  + IF(@clf_source_columns=1,0,1)
  + IF(@clf_channel_columns=4,0,1)
  + IF(@clf_channel_registered=1,0,1);
SELECT @clf_db AS db_name,@clf_required_tables AS required_table_count,
  @clf_source_columns AS source_schema_ready,@clf_channel_columns AS channel_snapshot_column_count,
  @clf_channel_registered AS channel_snapshot_upgrade_registered,
  @clf_failures AS precheck_failure_count;

SET @clf_finish_sql := IF(@clf_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result','SELECT * FROM STOP_CUSTOMER_LIFECYCLE_PRECHECK_FAILED');
PREPARE clf_finish_stmt FROM @clf_finish_sql; EXECUTE clf_finish_stmt; DEALLOCATE PREPARE clf_finish_stmt;
