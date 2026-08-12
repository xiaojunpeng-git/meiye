-- upgrade_key: 20260813-002-cashier-v3-sales-manager-fact
SET NAMES utf8mb4;
SET @sm_db := DATABASE();
SET @sm_workspace := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@sm_db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND ENGINE='InnoDB');
SELECT @sm_workspace AS workspace_line_table;
SELECT CASE WHEN @sm_workspace=1 THEN 'PRECHECK_OK' ELSE 'STOP_MISSING_WORKSPACE_LINE' END AS precheck_result;
