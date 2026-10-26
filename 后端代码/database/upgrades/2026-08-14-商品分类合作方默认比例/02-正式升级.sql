-- upgrade_key: 20260814-004-product-category-partner-default-ratio
-- MySQL 5.6 compatible and re-runnable. Existing configuration is preserved.
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=@db
     AND TABLE_NAME='eb_cashier_v3_report_category_config'
     AND COLUMN_NAME='partner_default_ratio')=0,
  'ALTER TABLE `eb_cashier_v3_report_category_config` ADD COLUMN `partner_default_ratio` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `enabled`',
  'SELECT ''partner_default_ratio already exists'''
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
UPDATE `eb_cashier_v3_report_category_config`
SET `partner_default_ratio` = 0
WHERE `partner_default_ratio` IS NULL OR `partner_default_ratio` > 100;
SELECT 'APPLY_OK' AS apply_result;
