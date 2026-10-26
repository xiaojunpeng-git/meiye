-- upgrade_key: 20260817-004-cashier-v3-source-fixed-flag
-- MySQL 5.6 compatible. This flag is stored only and has no checkout, order, or report behavior.
SET NAMES utf8mb4;

SET @is_fixed_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='eb_cashier_v3_business_source'
    AND COLUMN_NAME='is_fixed'
);
SET @is_fixed_sql := IF(
  @is_fixed_exists=0,
  'ALTER TABLE `eb_cashier_v3_business_source` ADD COLUMN `is_fixed` tinyint(1) unsigned NOT NULL DEFAULT ''0'' AFTER `status`',
  'SELECT ''is_fixed already exists'' AS apply_note'
);
PREPARE source_fixed_stmt FROM @is_fixed_sql;
EXECUTE source_fixed_stmt;
DEALLOCATE PREPARE source_fixed_stmt;

INSERT INTO eb_database_upgrade_log (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260817-004-cashier-v3-source-fixed-flag',
  '结账来源固定标记',
  '2026-08-17-结账来源固定标记/02-正式升级.sql',
  '', '', NOW(), 'codex', '已为结账来源增加仅存储的固定来源 0/1 标记'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20260817-004-cashier-v3-source-fixed-flag'
);

SELECT 'APPLY_OK' AS apply_result;
