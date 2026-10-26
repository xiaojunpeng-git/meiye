-- upgrade_key: 20261015-002-product-category-statistics-switch
-- Existing categories remain enabled; this configuration has no reporting effect yet.
SET @column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_store_product_category'
    AND COLUMN_NAME = 'statistics_enabled');
SET @ddl := IF(@column_exists = 0,
  'ALTER TABLE `eb_store_product_category` ADD COLUMN `statistics_enabled` tinyint(1) unsigned NOT NULL DEFAULT 1 COMMENT ''会员看板分类展示：1开启 0关闭'' AFTER `is_show`',
  'SELECT ''statistics_enabled_already_present'' AS apply_result');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
