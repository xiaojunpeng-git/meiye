-- upgrade_key: 20260731-006-cashier-v3-salesperson-workspace-draft
-- Run 01 before and 03 after this script. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @spd_db := DATABASE();

SELECT COUNT(*) INTO @spd_column_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@spd_db AND TABLE_NAME='eb_cashier_v3_workspace_line'
  AND COLUMN_NAME='salespeople_json';
SET @spd_sql := IF(@spd_column_exists=0,
  'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `salespeople_json` mediumtext NOT NULL AFTER `craftsmen_json`',
  'SELECT ''COLUMN_ALREADY_PRESENT'' AS apply_result');
PREPARE spd_stmt FROM @spd_sql; EXECUTE spd_stmt; DEALLOCATE PREPARE spd_stmt;

UPDATE `eb_cashier_v3_workspace_line`
SET `salespeople_json`='[]'
WHERE `salespeople_json`='';
SELECT 'APPLY_OK' AS apply_result;
