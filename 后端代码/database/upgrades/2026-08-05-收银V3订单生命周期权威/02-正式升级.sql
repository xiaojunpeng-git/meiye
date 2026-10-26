-- upgrade_key: 20260805-004-cashier-v3-order-lifecycle-authority
-- MySQL 5.6 compatible. New V3-only authority; no historical backfill.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_order_lifecycle_operation` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `source_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_order_no_snapshot` varchar(64) NOT NULL,
  `operation_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reason_snapshot` varchar(255) NOT NULL,
  `request_json` mediumtext NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` bigint(20) NOT NULL DEFAULT '0',
  `cash_refund_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reversed_cash_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `restored_principal_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `restored_bonus_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `deducted_principal_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `deducted_bonus_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `version` bigint(20) unsigned NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `settled_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation_id` (`operation_id`),
  UNIQUE KEY `uk_operation_no` (`operation_no`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_source` (`tenant_id`,`source_type`,`source_order_id`,`id`),
  KEY `idx_scope_member_time` (`tenant_id`,`store_id`,`member_id`,`settled_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 append-only order lifecycle authority';

SET @lifecycle_db := DATABASE();
SELECT COUNT(*) INTO @has_cash_refund FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_operation' AND COLUMN_NAME='cash_refund_cents';
SET @sql := IF(@has_cash_refund=0, 'ALTER TABLE `eb_cashier_v3_order_lifecycle_operation` ADD COLUMN `cash_refund_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `amount_cents`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT COUNT(*) INTO @has_reversed_cash FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_operation' AND COLUMN_NAME='reversed_cash_cents';
SET @sql := IF(@has_reversed_cash=0, 'ALTER TABLE `eb_cashier_v3_order_lifecycle_operation` ADD COLUMN `reversed_cash_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `cash_refund_cents`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT COUNT(*) INTO @has_restored_principal FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_operation' AND COLUMN_NAME='restored_principal_cents';
SET @sql := IF(@has_restored_principal=0, 'ALTER TABLE `eb_cashier_v3_order_lifecycle_operation` ADD COLUMN `restored_principal_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `cash_refund_cents`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT COUNT(*) INTO @has_restored_bonus FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_operation' AND COLUMN_NAME='restored_bonus_cents';
SET @sql := IF(@has_restored_bonus=0, 'ALTER TABLE `eb_cashier_v3_order_lifecycle_operation` ADD COLUMN `restored_bonus_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `restored_principal_cents`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT COUNT(*) INTO @has_deducted_principal FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_operation' AND COLUMN_NAME='deducted_principal_cents';
SET @sql := IF(@has_deducted_principal=0, 'ALTER TABLE `eb_cashier_v3_order_lifecycle_operation` ADD COLUMN `deducted_principal_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `restored_bonus_cents`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT COUNT(*) INTO @has_deducted_bonus FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_operation' AND COLUMN_NAME='deducted_bonus_cents';
SET @sql := IF(@has_deducted_bonus=0, 'ALTER TABLE `eb_cashier_v3_order_lifecycle_operation` ADD COLUMN `deducted_bonus_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `deducted_principal_cents`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_order_reopen_draft` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `draft_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `source_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_order_no_snapshot` varchar(64) NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `line_snapshot_json` mediumtext NOT NULL,
  `draft_status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_draft_id` (`draft_id`),
  UNIQUE KEY `uk_operation` (`operation_id`),
  KEY `idx_source_status` (`tenant_id`,`store_id`,`source_order_id`,`draft_status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 immutable sales-order reopen drafts';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_order_lifecycle_financial_reversal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reversal_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `source_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_order_no_snapshot` varchar(64) NOT NULL,
  `reversal_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `cash_refund_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `reversed_cash_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `restored_principal_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `restored_bonus_cents` bigint(20) unsigned NOT NULL DEFAULT '0',
  `payment_fact_ids_json` mediumtext NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reversal_id` (`reversal_id`),
  UNIQUE KEY `uk_operation` (`operation_id`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_source` (`tenant_id`,`source_order_id`,`id`),
  KEY `idx_scope_time` (`tenant_id`,`store_id`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 append-only sales refund and void financial audit';

SELECT COUNT(*) INTO @has_financial_reversed_cash FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@lifecycle_db AND TABLE_NAME='eb_cashier_v3_order_lifecycle_financial_reversal' AND COLUMN_NAME='reversed_cash_cents';
SET @sql := IF(@has_financial_reversed_cash=0, 'ALTER TABLE `eb_cashier_v3_order_lifecycle_financial_reversal` ADD COLUMN `reversed_cash_cents` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `cash_refund_cents`', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_order_lifecycle_debt_reversal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reversal_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `source_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `debt_id` bigint(20) unsigned NOT NULL,
  `debt_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `cancelled_debt_cents` bigint(20) unsigned NOT NULL,
  `reversal_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reversal_id` (`reversal_id`),
  UNIQUE KEY `uk_operation_debt` (`operation_id`,`debt_id`),
  KEY `idx_source` (`tenant_id`,`source_order_id`,`id`),
  KEY `idx_debt` (`debt_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 append-only cancelled debt authority';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_order_lifecycle_benefit_reversal` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reversal_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `source_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `sales_order_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `issuance_receipt_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `card_holder_id` bigint(20) unsigned NOT NULL,
  `legacy_order_id` bigint(20) unsigned NOT NULL,
  `benefit_detail_ids_json` mediumtext NOT NULL,
  `reversal_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reversal_id` (`reversal_id`),
  UNIQUE KEY `uk_operation_receipt` (`operation_id`,`issuance_receipt_id`),
  KEY `idx_source` (`tenant_id`,`source_order_id`,`id`),
  KEY `idx_holder` (`tenant_id`,`card_holder_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 append-only card benefit revocation authority';

SELECT 'APPLY_OK' AS apply_result;
