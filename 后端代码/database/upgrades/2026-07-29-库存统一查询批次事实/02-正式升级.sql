-- upgrade_key: 20260729-003-inventory-batch-query-facts
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @now := UNIX_TIMESTAMP();
SET @business_date := DATE(FROM_UNIXTIME(@now));

CREATE TABLE IF NOT EXISTS `eb_inventory_location` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_path` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_name_snapshot` varchar(100) NOT NULL DEFAULT '',
  `location_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'STORE',
  `owner_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `location_code` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `location_name` varchar(100) NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_name_snapshot` varchar(100) NOT NULL DEFAULT '',
  `is_default` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `location_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_location_code` (`tenant_id`,`location_code`),
  KEY `idx_scope_owner` (`tenant_id`,`organization_path`,`location_type`,`owner_id`,`location_status`,`id`),
  KEY `idx_store_default` (`tenant_id`,`store_id`,`is_default`,`location_status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='physical inventory location authority';

SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='eb_inventory_stock' AND column_name='location_id');
SET @sql := IF(@exists=0,
  'ALTER TABLE `eb_inventory_stock` ADD COLUMN `location_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `organization_path`',
  'SELECT ''skip inventory_stock.location_id''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `eb_inventory_location`
  (`tenant_id`,`organization_id`,`organization_path`,`organization_name_snapshot`,`location_type`,
   `owner_id`,`location_code`,`location_name`,`store_id`,`store_name_snapshot`,`is_default`,
   `location_status`,`version`,`created_at`,`updated_at`)
SELECT s.tenant_id,s.organization_id,s.organization_path,'历史组织','STORE',s.store_id,
       CONCAT('STORE-',s.store_id),'默认仓库',s.store_id,'历史门店',1,'ACTIVE',1,@now,@now
FROM `eb_inventory_stock` s
GROUP BY s.tenant_id,s.organization_id,s.organization_path,s.store_id;

UPDATE `eb_inventory_stock` s
JOIN `eb_inventory_location` l
  ON l.tenant_id=s.tenant_id AND l.location_type='STORE' AND l.store_id=s.store_id AND l.is_default=1
SET s.location_id=l.id
WHERE s.location_id=0;

SET @old_unique := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='eb_inventory_stock' AND index_name='uk_tenant_store_sku_status');
SET @sql := IF(@old_unique>0,
  'ALTER TABLE `eb_inventory_stock` DROP INDEX `uk_tenant_store_sku_status`',
  'SELECT ''skip old inventory stock unique''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @new_unique := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='eb_inventory_stock' AND index_name='uk_tenant_location_sku_status');
SET @sql := IF(@new_unique=0,
  'ALTER TABLE `eb_inventory_stock` ADD UNIQUE KEY `uk_tenant_location_sku_status` (`tenant_id`,`location_id`,`consumable_product_id`,`sku_id`,`stock_status`), ADD KEY `idx_location_status` (`tenant_id`,`location_id`,`stock_status`,`id`)',
  'SELECT ''skip new inventory stock unique''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='eb_inventory_batch' AND column_name='origin_batch_id');
SET @sql := IF(@exists=0,
  'ALTER TABLE `eb_inventory_batch` ADD COLUMN `origin_batch_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `stock_id`, ADD COLUMN `source_batch_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `origin_batch_id`, ADD COLUMN `received_business_date` date NULL DEFAULT NULL AFTER `received_at`, ADD COLUMN `product_name_snapshot` varchar(120) NOT NULL DEFAULT '''' AFTER `received_business_date`, ADD COLUMN `sku_name_snapshot` varchar(120) NOT NULL DEFAULT '''' AFTER `product_name_snapshot`, ADD COLUMN `product_code_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `sku_name_snapshot`, ADD COLUMN `barcode_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `product_code_snapshot`, ADD COLUMN `brand_name_snapshot` varchar(100) NOT NULL DEFAULT '''' AFTER `barcode_snapshot`, ADD COLUMN `category_name_snapshot` varchar(100) NOT NULL DEFAULT '''' AFTER `brand_name_snapshot`, ADD COLUMN `source_order_no_snapshot` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `category_name_snapshot`, ADD COLUMN `data_quality` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''COMPLETE'' AFTER `source_order_no_snapshot`',
  'SELECT ''skip inventory batch snapshots''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `eb_inventory_batch`
SET origin_batch_id=IF(origin_batch_id=0,id,origin_batch_id),
    received_business_date=IFNULL(received_business_date,DATE(FROM_UNIXTIME(received_at))),
    data_quality=IF(product_name_snapshot='' OR manufactured_date IS NULL OR expire_date IS NULL,'HISTORICAL_UNKNOWN',data_quality),
    product_name_snapshot=IF(product_name_snapshot='','历史商品',product_name_snapshot),
    sku_name_snapshot=IF(sku_name_snapshot='','历史规格',sku_name_snapshot),
    source_order_no_snapshot=IF(source_order_no_snapshot='',CONCAT('OPENING-',id),source_order_no_snapshot)
WHERE origin_batch_id=0 OR received_business_date IS NULL OR product_name_snapshot='' OR sku_name_snapshot='';

CREATE TABLE IF NOT EXISTS `eb_inventory_batch_movement_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fact_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `organization_path` varchar(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `location_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `stock_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `batch_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `direction` smallint(6) NOT NULL DEFAULT '1',
  `quantity_units` bigint(20) unsigned NOT NULL DEFAULT '0',
  `unit_cost_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `cost_amount_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `fact_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SETTLED',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_id` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_detail_id` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `reversal_of` bigint(20) unsigned NOT NULL DEFAULT '0',
  `business_date` date NOT NULL,
  `occurred_at` int(11) unsigned NOT NULL DEFAULT '0',
  `settled_at` int(11) unsigned NOT NULL DEFAULT '0',
  `recorded_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_fact_key` (`tenant_id`,`fact_key`),
  KEY `idx_scope_cutoff_batch` (`tenant_id`,`location_id`,`business_date`,`fact_status`,`batch_id`,`id`),
  KEY `idx_batch_time` (`batch_id`,`business_date`,`id`),
  KEY `idx_source` (`tenant_id`,`source_type`,`source_id`,`source_detail_id`,`id`),
  KEY `idx_reversal` (`tenant_id`,`reversal_of`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='append only inventory batch quantity and cost fact';

INSERT IGNORE INTO `eb_inventory_batch_movement_fact`
  (`fact_key`,`tenant_id`,`organization_id`,`organization_path`,`location_id`,`store_id`,`stock_id`,`batch_id`,
   `direction`,`quantity_units`,`unit_cost_cents`,`cost_amount_cents`,`fact_status`,`source_type`,`source_id`,
   `source_detail_id`,`reversal_of`,`business_date`,`occurred_at`,`settled_at`,`recorded_at`)
SELECT CONCAT('opening:',b.id),s.tenant_id,s.organization_id,s.organization_path,s.location_id,s.store_id,s.id,b.id,
       1,b.available_quantity_units,b.unit_cost_cents,
       ROUND(b.available_quantity_units*b.unit_cost_cents/POW(10,s.quantity_scale),0),
       'SETTLED','opening_balance',CONCAT('batch-',b.id),
       CONCAT('batch-',b.id),0,@business_date,@now,@now,@now
FROM `eb_inventory_batch` b
JOIN `eb_inventory_stock` s ON s.id=b.stock_id
WHERE b.available_quantity_units>0;

SELECT 'APPLY_OK' AS apply_result;
