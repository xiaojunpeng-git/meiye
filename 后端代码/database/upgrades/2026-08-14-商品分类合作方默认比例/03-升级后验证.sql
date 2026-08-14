-- upgrade_key: 20260814-004-product-category-partner-default-ratio
SET NAMES utf8mb4;
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_report_category_config'
  AND COLUMN_NAME = 'partner_default_ratio';
SELECT COUNT(*) AS invalid_ratio_rows
FROM `eb_cashier_v3_report_category_config`
WHERE `partner_default_ratio` < 0 OR `partner_default_ratio` > 100;
