-- upgrade_key: 20260804-005-cashier-v3-recharge-payment-fact-read-index
-- Read-only exact index verification. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rpf_db := DATABASE();
SET @rpf_failures := 0;

SELECT COUNT(*) INTO @rpf_projection_index
FROM (
  SELECT INDEX_NAME, MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@rpf_db AND TABLE_NAME='eb_cashier_v3_payment_fact'
    AND INDEX_NAME='idx_recharge_projection'
  GROUP BY INDEX_NAME
  HAVING non_unique=1
    AND index_columns='source_document_type,status,store_id,order_no_snapshot'
) rpf_indexes;

SELECT COUNT(*) INTO @rpf_index_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@rpf_db AND TABLE_NAME='eb_cashier_v3_payment_fact'
  AND INDEX_NAME='idx_recharge_projection';

SET @rpf_failures := @rpf_failures
  + IF(@rpf_projection_index=1,0,1)
  + IF(@rpf_index_count=4,0,1);

SELECT @rpf_projection_index AS exact_projection_index_count,
  @rpf_index_count AS projection_index_column_count,
  @rpf_failures AS postcheck_failure_count;

SET @rpf_finish_sql := IF(
  @rpf_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_RECHARGE_PAYMENT_FACT_INDEX_POSTCHECK_FAILED'
);
PREPARE rpf_finish_stmt FROM @rpf_finish_sql;
EXECUTE rpf_finish_stmt;
DEALLOCATE PREPARE rpf_finish_stmt;
