-- upgrade_key: 20260810-001-report-channel-fact-snapshot
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rcf_db := DATABASE();
SET @rcf_failures := 0;

SELECT COUNT(*) INTO @rcf_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rcf_db
  AND TABLE_NAME IN ('eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact','eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact')
  AND COLUMN_NAME IN ('business_source_primary_id','business_source_primary_name_snapshot','business_source_secondary_id','business_source_secondary_name_snapshot','business_source_label_snapshot');

SELECT COUNT(*) INTO @rcf_indexes
FROM (
  SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@rcf_db
    AND TABLE_NAME IN ('eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact','eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact')
    AND INDEX_NAME='idx_business_source_date'
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING columns_in_index='tenant_id,store_id,business_source_primary_id,business_date,status,id'
) rcf_index_rows;

SET @rcf_failures := IF(@rcf_columns=20,0,1)+IF(@rcf_indexes=4,0,1);
SELECT @rcf_columns AS channel_snapshot_column_count,@rcf_indexes AS exact_channel_index_count,@rcf_failures AS postcheck_failure_count;

SET @rcf_finish_sql := IF(@rcf_failures=0,'SELECT ''POSTCHECK_OK'' AS postcheck_result','SELECT * FROM STOP_REPORT_CHANNEL_FACT_SNAPSHOT_POSTCHECK_FAILED');
PREPARE rcf_finish_stmt FROM @rcf_finish_sql; EXECUTE rcf_finish_stmt; DEALLOCATE PREPARE rcf_finish_stmt;
