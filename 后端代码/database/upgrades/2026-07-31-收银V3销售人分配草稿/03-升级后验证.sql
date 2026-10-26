-- upgrade_key: 20260731-006-cashier-v3-salesperson-workspace-draft
-- Read-only postcheck; MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @spd_db := DATABASE();

SELECT COUNT(*) INTO @spd_column_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@spd_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND COLUMN_NAME='salespeople_json' AND DATA_TYPE='mediumtext' AND IS_NULLABLE='NO';
SELECT COUNT(*) INTO @spd_invalid_rows
FROM `eb_cashier_v3_workspace_line`
WHERE `salespeople_json` IS NULL OR `salespeople_json`='';
SELECT @spd_column_ok AS salesperson_column_count,@spd_invalid_rows AS invalid_row_count;
SET @spd_abort := IF(@spd_column_ok=1 AND @spd_invalid_rows=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_SALESPERSON_DRAFT_POSTCHECK_FAILED');
PREPARE spd_stmt FROM @spd_abort; EXECUTE spd_stmt; DEALLOCATE PREPARE spd_stmt;
