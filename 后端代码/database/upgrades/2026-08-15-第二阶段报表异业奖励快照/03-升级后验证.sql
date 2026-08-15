-- upgrade_key: 20260815-001-phase-two-report-cross-industry-reward
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @reward_db := DATABASE();

SELECT COUNT(*) INTO @reward_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@reward_db
  AND COLUMN_NAME='reward_amount_cents'
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_business_source_selection',
    'eb_cashier_v3_sales_order'
  )
  AND DATA_TYPE='bigint'
  AND IS_NULLABLE='NO';

SET @reward_finish_sql := IF(@reward_columns=2,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_PHASE_TWO_REWARD_POSTCHECK_FAILED');
PREPARE reward_stmt FROM @reward_finish_sql;
EXECUTE reward_stmt;
DEALLOCATE PREPARE reward_stmt;
