-- upgrade_key: 20261002-001-inventory-request-acceptance-party
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_inventory_stock_request_acceptance_party` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `party_code` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `party_name` varchar(64) NOT NULL DEFAULT '',
  `status` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `sort` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_party_code` (`tenant_id`,`party_code`),
  KEY `idx_tenant_status_sort` (`tenant_id`,`status`,`sort`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='总部供货请货受理方目录；不代表库存仓库';

SET @has_acceptance_party_id := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='acceptance_party_id');
SET @sql := IF(@has_acceptance_party_id=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `acceptance_party_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `supply_party_name_snapshot`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_acceptance_party_name := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='acceptance_party_name_snapshot');
SET @sql := IF(@has_acceptance_party_name=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `acceptance_party_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `acceptance_party_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_acceptance_party_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND index_name='idx_supply_acceptance_status');
SET @sql := IF(@has_acceptance_party_index=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD KEY `idx_supply_acceptance_status` (`tenant_id`,`supply_party_type`,`supply_party_id`,`acceptance_party_id`,`document_status`,`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO `eb_inventory_stock_request_acceptance_party`
  (`tenant_id`,`party_code`,`party_name`,`status`,`sort`,`created_at`,`updated_at`)
VALUES
  ('0','HEADQUARTERS','总部',1,10,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('0','OFFICE','办公室',1,20,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
  ('0','PROCUREMENT','采购部',1,30,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `party_name`=VALUES(`party_name`),`status`=1,`sort`=VALUES(`sort`),`updated_at`=UNIX_TIMESTAMP();

SELECT 'APPLY_OK' AS apply_result;
