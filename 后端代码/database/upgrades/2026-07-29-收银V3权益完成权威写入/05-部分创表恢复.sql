-- upgrade_key: 20260729-014-cashier-v3-entitlement-completion-persistence-v1
-- Read-only, non-destructive partial-DDL validator.
SET NAMES utf8mb4;
SET @ecp_db := DATABASE();
SET @ecp_failures := 0;

SELECT COUNT(*) INTO @ecp_existing_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_completion_receipt',
  'eb_cashier_v3_entitlement_writeoff_fact',
  'eb_cashier_v3_entitlement_service_fact'
);
SELECT COUNT(*) INTO @ecp_shape_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_completion_receipt',
  'eb_cashier_v3_entitlement_writeoff_fact',
  'eb_cashier_v3_entitlement_service_fact'
) AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SET @ecp_rows := 0;
SET @ecp_row_sql := CONCAT(
  'SELECT ',
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME='eb_cashier_v3_entitlement_completion_receipt'),
    '(SELECT COUNT(*) FROM eb_cashier_v3_entitlement_completion_receipt)+','0+'),
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME='eb_cashier_v3_entitlement_writeoff_fact'),
    '(SELECT COUNT(*) FROM eb_cashier_v3_entitlement_writeoff_fact)+','0+'),
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact'),
    '(SELECT COUNT(*) FROM eb_cashier_v3_entitlement_service_fact)','0'),
  ' INTO @ecp_rows'
);
PREPARE ecp_row_stmt FROM @ecp_row_sql;
EXECUTE ecp_row_stmt;
DEALLOCATE PREPARE ecp_row_stmt;

SELECT COUNT(*) INTO @ecp_signature_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ecp_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_entitlement_completion_receipt.receipt_id',
  'eb_cashier_v3_entitlement_completion_receipt.plan_fingerprint',
  'eb_cashier_v3_entitlement_completion_receipt.status',
  'eb_cashier_v3_entitlement_writeoff_fact.writeoff_id',
  'eb_cashier_v3_entitlement_writeoff_fact.source_detail_id',
  'eb_cashier_v3_entitlement_writeoff_fact.quantity',
  'eb_cashier_v3_entitlement_service_fact.service_fact_id',
  'eb_cashier_v3_entitlement_service_fact.project_id',
  'eb_cashier_v3_entitlement_service_fact.quantity'
);
SET @ecp_expected_signatures :=
  IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME='eb_cashier_v3_entitlement_completion_receipt'),3,0)
  + IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME='eb_cashier_v3_entitlement_writeoff_fact'),3,0)
  + IF(EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact'),3,0);
SET @ecp_failures := @ecp_failures
  + IF(@ecp_existing_count BETWEEN 1 AND 2,0,1)
  + IF(@ecp_shape_count=@ecp_existing_count,0,1)
  + IF(@ecp_rows=0,0,1)
  + IF(@ecp_signature_count=@ecp_expected_signatures,0,1);

SELECT @ecp_existing_count AS existing_table_count,@ecp_rows AS existing_row_count,
  @ecp_signature_count AS signature_count,@ecp_failures AS recovery_failure_count;
SET @ecp_finish_sql := IF(
  @ecp_failures=0,
  'SELECT ''PARTIAL_DDL_RECOVERY_READY'' AS recovery_result',
  'SELECT * FROM STOP_ENTITLEMENT_COMPLETION_PARTIAL_RECOVERY_FAILED'
);
PREPARE ecp_finish_stmt FROM @ecp_finish_sql;
EXECUTE ecp_finish_stmt;
DEALLOCATE PREPARE ecp_finish_stmt;
