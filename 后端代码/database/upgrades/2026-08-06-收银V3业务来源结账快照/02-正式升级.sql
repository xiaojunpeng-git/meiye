-- upgrade_key: 20260806-003-cashier-v3-checkout-business-source-snapshot
-- MySQL 5.6 compatible. This is an append-only source-selection authority.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_checkout_business_source_selection` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `checkout_kind` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `primary_source_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `primary_source_name_snapshot` varchar(64) NOT NULL DEFAULT '',
  `secondary_source_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `secondary_source_name_snapshot` varchar(64) NOT NULL DEFAULT '',
  `source_label_snapshot` varchar(140) NOT NULL DEFAULT '',
  `selection_version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `updated_by_operator_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_checkout_source` (`checkout_kind`,`checkout_request_id`),
  KEY `idx_scope_checkout` (`tenant_id`,`store_id`,`checkout_kind`,`checkout_request_id`,`id`),
  KEY `idx_primary_source` (`primary_source_id`,`secondary_source_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 checkout business-source selection authority';

SET @db := DATABASE();
SET @table := 'eb_cashier_v3_sales_order';
SET @column := 'business_source_primary_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sales_order` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_document_no_snapshot`',
  'SELECT ''business_source_primary_id already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_primary_name_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sales_order` ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_primary_id`',
  'SELECT ''business_source_primary_name_snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_secondary_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sales_order` ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `business_source_primary_name_snapshot`',
  'SELECT ''business_source_secondary_id already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_secondary_name_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sales_order` ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_secondary_id`',
  'SELECT ''business_source_secondary_name_snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_label_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sales_order` ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT '''' AFTER `business_source_secondary_name_snapshot`',
  'SELECT ''business_source_label_snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @index := 'idx_business_source';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name=@table AND index_name=@index)=0,
  'ALTER TABLE `eb_cashier_v3_sales_order` ADD KEY `idx_business_source` (`tenant_id`,`store_id`,`business_source_primary_id`,`business_date`,`id`)',
  'SELECT ''idx_business_source already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table := 'eb_user_recharge';
SET @column := 'business_source_primary_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_user_recharge` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source`',
  'SELECT ''recharge business_source_primary_id already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_primary_name_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_user_recharge` ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_primary_id`',
  'SELECT ''recharge business_source_primary_name_snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_secondary_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_user_recharge` ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `business_source_primary_name_snapshot`',
  'SELECT ''recharge business_source_secondary_id already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_secondary_name_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_user_recharge` ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_secondary_id`',
  'SELECT ''recharge business_source_secondary_name_snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_label_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_user_recharge` ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT '''' AFTER `business_source_secondary_name_snapshot`',
  'SELECT ''recharge business_source_label_snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'APPLY_OK' AS apply_result;
