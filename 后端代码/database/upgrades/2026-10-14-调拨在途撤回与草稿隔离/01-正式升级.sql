-- MySQL 5.7 compatible; every DDL step can be retried after an interrupted upgrade.
SET @schema_name := DATABASE();

SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `eb_inventory_cross_transfer_document` ADD COLUMN `withdrawal_count` int(10) unsigned NOT NULL DEFAULT 0 AFTER `dispatched_at`',
  'SELECT 1') INTO @ddl
FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_document' AND column_name='withdrawal_count';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `eb_inventory_cross_transfer_line` ADD COLUMN `line_generation` int(10) unsigned NOT NULL DEFAULT 1 AFTER `line_no`',
  'SELECT 1') INTO @ddl
FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_line' AND column_name='line_generation';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `eb_inventory_cross_transfer_line` ADD COLUMN `retired_at` int(11) unsigned NOT NULL DEFAULT 0 AFTER `line_generation`',
  'SELECT 1') INTO @ddl
FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_line' AND column_name='retired_at';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `eb_inventory_cross_transfer_batch_allocation` ADD COLUMN `dispatch_attempt` int(10) unsigned NOT NULL DEFAULT 1 AFTER `line_id`',
  'SELECT 1') INTO @ddl
FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_batch_allocation' AND column_name='dispatch_attempt';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT IF(COUNT(*) = 0,
  'ALTER TABLE `eb_inventory_cross_transfer_batch_allocation` ADD COLUMN `withdrawn_at` int(11) unsigned NOT NULL DEFAULT 0 AFTER `received_at`',
  'SELECT 1') INTO @ddl
FROM information_schema.columns WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_batch_allocation' AND column_name='withdrawn_at';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT IF(COUNT(*) > 0, 'ALTER TABLE `eb_inventory_cross_transfer_line` DROP INDEX `uk_document_line_no`', 'SELECT 1') INTO @ddl
FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_line' AND index_name='uk_document_line_no';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT IF(COUNT(*) = 0, 'ALTER TABLE `eb_inventory_cross_transfer_line` ADD UNIQUE KEY `uk_document_generation_line` (`document_id`,`line_generation`,`line_no`)', 'SELECT 1') INTO @ddl
FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_line' AND index_name='uk_document_generation_line';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT IF(COUNT(*) > 0, 'ALTER TABLE `eb_inventory_cross_transfer_batch_allocation` DROP INDEX `uk_document_source_batch`', 'SELECT 1') INTO @ddl
FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_batch_allocation' AND index_name='uk_document_source_batch';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT IF(COUNT(*) = 0, 'ALTER TABLE `eb_inventory_cross_transfer_batch_allocation` ADD UNIQUE KEY `uk_document_attempt_batch` (`document_id`,`dispatch_attempt`,`from_batch_id`)', 'SELECT 1') INTO @ddl
FROM information_schema.statistics WHERE table_schema=@schema_name AND table_name='eb_inventory_cross_transfer_batch_allocation' AND index_name='uk_document_attempt_batch';
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `eb_inventory_cross_transfer_lifecycle_operation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `transfer_document_id` bigint(20) unsigned NOT NULL,
  `action` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `dispatch_attempt` int(10) unsigned NOT NULL DEFAULT 0,
  `idempotency_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason` varchar(500) NOT NULL DEFAULT '',
  `operator_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `occurred_at` int(11) unsigned NOT NULL,
  `recorded_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  UNIQUE KEY `uk_document_action_attempt` (`transfer_document_id`,`action`,`dispatch_attempt`),
  KEY `idx_document_history` (`transfer_document_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='调拨撤回与草稿作废操作审计';

-- Platform middleware requires explicit API registrations; neither route grants a broader capability.
INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台调拨撤回发货','','','','product/inventory/v3/hq/cross-transfer/<id>/withdraw','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer/<id>/withdraw' AND `methods`='POST' AND `is_del`=0);

INSERT INTO `eb_system_menus`
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','平台调拨撤回后作废','','','','product/inventory/v3/hq/cross-transfer/<id>/reverse','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `type`=1 AND `api_url`='product/inventory/v3/hq/cross-transfer/<id>/reverse' AND `methods`='POST' AND `is_del`=0);
