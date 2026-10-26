-- upgrade_key: 20260729-008-cashier-v3-sale-cart-authority
-- Run 01 first. Every DDL statement is replay-safe after a partial MySQL commit.
SET NAMES utf8mb4;

SET @c2s_table := 'eb_cashier_v3_workspace_line';

SELECT COUNT(*) INTO @c2s_exists FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@c2s_table AND COLUMN_NAME='catalog_product_id';
SET @c2s_sql := IF(@c2s_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `catalog_product_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `project_id`',
 'SELECT ''catalog_product_id already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(*) INTO @c2s_exists FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@c2s_table AND COLUMN_NAME='catalog_sku_id';
SET @c2s_sql := IF(@c2s_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `catalog_sku_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `catalog_product_id`',
 'SELECT ''catalog_sku_id already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(*) INTO @c2s_exists FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@c2s_table AND COLUMN_NAME='catalog_product_type';
SET @c2s_sql := IF(@c2s_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `catalog_product_type` tinyint(3) unsigned NOT NULL DEFAULT ''0'' AFTER `catalog_sku_id`',
 'SELECT ''catalog_product_type already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(*) INTO @c2s_exists FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@c2s_table AND COLUMN_NAME='unit_price_cents';
SET @c2s_sql := IF(@c2s_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `unit_price_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `detail_version`',
 'SELECT ''unit_price_cents already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(*) INTO @c2s_exists FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@c2s_table AND COLUMN_NAME='original_unit_price_cents';
SET @c2s_sql := IF(@c2s_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `original_unit_price_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `unit_price_cents`',
 'SELECT ''original_unit_price_cents already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(*) INTO @c2s_exists FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@c2s_table AND COLUMN_NAME='authority_fingerprint';
SET @c2s_sql := IF(@c2s_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `authority_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `original_unit_price_cents`',
 'SELECT ''authority_fingerprint already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(*) INTO @c2s_exists FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@c2s_table AND COLUMN_NAME='authority_snapshot_json';
SET @c2s_sql := IF(@c2s_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_line` ADD COLUMN `authority_snapshot_json` mediumtext NULL AFTER `authority_fingerprint`',
 'SELECT ''authority_snapshot_json already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(*) INTO @c2s_index_exists FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@c2s_table AND INDEX_NAME='idx_catalog_source';
SET @c2s_sql := IF(@c2s_index_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_line` ADD KEY `idx_catalog_source` (`catalog_product_id`,`catalog_sku_id`,`line_role`,`id`)',
 'SELECT ''idx_catalog_source already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(DISTINCT INDEX_NAME) INTO @c2s_relation_leading_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related'
  AND SEQ_IN_INDEX=1 AND COLUMN_NAME='card_product_id';
SET @c2s_sql := IF(@c2s_relation_leading_index=0,
 'ALTER TABLE `eb_store_card_related` ADD KEY `idx_c2_card_definition` (`card_product_id`,`id`)',
 'SELECT ''card definition leading index already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT COUNT(DISTINCT INDEX_NAME) INTO @c2s_component_leading_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related'
  AND SEQ_IN_INDEX=1 AND COLUMN_NAME='product_id';
SET @c2s_sql := IF(@c2s_component_leading_index=0,
 'ALTER TABLE `eb_store_card_related` ADD KEY `idx_c2_component_reverse` (`product_id`,`card_product_id`,`id`)',
 'SELECT ''component reverse leading index already exists'' AS apply_note');
PREPARE c2s_stmt FROM @c2s_sql; EXECUTE c2s_stmt; DEALLOCATE PREPARE c2s_stmt;

SELECT 'APPLY_OK' AS apply_result;
