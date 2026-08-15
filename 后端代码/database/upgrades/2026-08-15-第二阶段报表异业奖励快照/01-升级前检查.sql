-- upgrade_key: 20260815-001-phase-two-report-cross-industry-reward
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @reward_db := DATABASE();

SELECT COUNT(*) INTO @reward_required_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@reward_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_business_source_selection',
    'eb_cashier_v3_sales_order',
    'eb_database_upgrade_log'
  )
  AND ENGINE='InnoDB';

SET @reward_finish_sql := IF(@reward_required_tables=3,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_PHASE_TWO_REWARD_PRECHECK_FAILED');
PREPARE reward_stmt FROM @reward_finish_sql;
EXECUTE reward_stmt;
DEALLOCATE PREPARE reward_stmt;
