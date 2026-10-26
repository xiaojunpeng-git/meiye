-- upgrade_key: 20260814-004-product-category-partner-default-ratio
SET NAMES utf8mb4;
SELECT DATABASE() AS database_name;
SELECT COUNT(*) AS category_config_rows
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_report_category_config';
SELECT COUNT(*) AS ratio_column_rows
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_report_category_config'
  AND COLUMN_NAME = 'partner_default_ratio';
