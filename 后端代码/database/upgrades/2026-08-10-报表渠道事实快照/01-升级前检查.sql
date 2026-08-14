-- upgrade_key: 20260810-001-report-channel-fact-snapshot
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rcf_db := DATABASE();
SET @rcf_failures := 0;

SELECT COUNT(*) INTO @rcf_upgrade_log
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rcf_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @rcf_fact_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rcf_db AND TABLE_NAME IN (
  'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
  'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
) AND ENGINE='InnoDB';

SET @rcf_failures := IF(@rcf_upgrade_log=1,0,1)+IF(@rcf_fact_tables=4,0,1);
SELECT @rcf_db AS db_name,@rcf_fact_tables AS fact_table_count,@rcf_failures AS precheck_failure_count;

SET @rcf_finish_sql := IF(@rcf_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result','SELECT * FROM STOP_REPORT_CHANNEL_FACT_SNAPSHOT_PRECHECK_FAILED');
PREPARE rcf_finish_stmt FROM @rcf_finish_sql; EXECUTE rcf_finish_stmt; DEALLOCATE PREPARE rcf_finish_stmt;
