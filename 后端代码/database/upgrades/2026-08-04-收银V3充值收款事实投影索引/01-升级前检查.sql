-- upgrade_key: 20260804-005-cashier-v3-recharge-payment-fact-read-index
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rpf_db := DATABASE();
SET @rpf_failures := 0;

SELECT COUNT(*) INTO @rpf_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rpf_db AND TABLE_NAME='eb_database_upgrade_log';

SET @rpf_registered := 0;
SET @rpf_registered_sql := IF(
  @rpf_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @rpf_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260804-005-cashier-v3-recharge-payment-fact-read-index''',
  'SELECT 0 INTO @rpf_registered'
);
PREPARE rpf_registered_stmt FROM @rpf_registered_sql;
EXECUTE rpf_registered_stmt;
DEALLOCATE PREPARE rpf_registered_stmt;

SELECT COUNT(*) INTO @rpf_target_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rpf_db
  AND TABLE_NAME='eb_cashier_v3_payment_fact'
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @rpf_existing_index
FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@rpf_db AND TABLE_NAME='eb_cashier_v3_payment_fact'
    AND INDEX_NAME='idx_recharge_projection'
  GROUP BY INDEX_NAME
  HAVING index_columns='source_document_type,status,store_id,order_no_snapshot'
) rpf_indexes;

SET @rpf_failures := @rpf_failures
  + IF(@rpf_upgrade_log_exists=1,0,1)
  + IF(@rpf_registered=0,0,1)
  + IF(@rpf_target_table=1,0,1)
  + IF(@rpf_existing_index=0,0,1);

SELECT @rpf_db AS db_name, @rpf_registered AS already_registered,
  @rpf_target_table AS target_table_count,
  @rpf_existing_index AS existing_projection_index_count,
  @rpf_failures AS precheck_failure_count;

SET @rpf_finish_sql := IF(
  @rpf_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_RECHARGE_PAYMENT_FACT_INDEX_PRECHECK_FAILED'
);
PREPARE rpf_finish_stmt FROM @rpf_finish_sql;
EXECUTE rpf_finish_stmt;
DEALLOCATE PREPARE rpf_finish_stmt;
