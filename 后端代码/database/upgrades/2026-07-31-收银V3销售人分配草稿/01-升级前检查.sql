-- upgrade_key: 20260731-006-cashier-v3-salesperson-workspace-draft
-- Read-only precheck; MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @spd_db := DATABASE();

SELECT COUNT(*) INTO @spd_log_table
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@spd_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND DATA_TYPE='varchar';
SELECT COUNT(*) INTO @spd_workspace_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@spd_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @spd_existing_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@spd_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND COLUMN_NAME='salespeople_json';
SELECT COUNT(*) INTO @spd_existing_definition_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@spd_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND COLUMN_NAME='salespeople_json' AND DATA_TYPE='mediumtext' AND IS_NULLABLE='NO';

SET @spd_failures := IF(@spd_log_table=1,0,1)
  + IF(@spd_workspace_table=1,0,1)
  + IF(@spd_existing_column IN (0,1),0,1)
  + IF(@spd_existing_column=0 OR @spd_existing_definition_ok=1,0,1);
SELECT @spd_log_table AS upgrade_log_ready,@spd_workspace_table AS workspace_line_ready,
  @spd_existing_column AS existing_column_count,@spd_existing_definition_ok AS existing_definition_ok,
  @spd_failures AS precheck_failure_count;
SET @spd_abort := IF(@spd_failures=0,'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_SALESPERSON_DRAFT_PRECHECK_FAILED');
PREPARE spd_stmt FROM @spd_abort; EXECUTE spd_stmt; DEALLOCATE PREPARE spd_stmt;
