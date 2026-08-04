-- upgrade_key: 20260803-005-cashier-v3-business-document-numbers
-- MySQL 5.6 compatible. New documents only; existing business records remain untouched.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_business_document_sequence` (
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `document_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `current_value` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`tenant_id`,`document_type`,`business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier visible number sequence by tenant type date';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_business_document_no` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `source_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `document_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `business_date` date NOT NULL,
  `document_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_source` (`tenant_id`,`source_type`,`source_id`),
  UNIQUE KEY `uk_tenant_document_no` (`tenant_id`,`document_no`),
  KEY `idx_tenant_type_date` (`tenant_id`,`document_type`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier visible document mapping for new business records';

SET @cashier_document_db := DATABASE();
SELECT COUNT(*) INTO @cashier_document_gift_no_exists
FROM information_schema.columns
WHERE table_schema = @cashier_document_db
  AND table_name = 'eb_cashier_v3_recharge_gift_authority'
  AND column_name = 'gift_no';
SET @cashier_document_sql := IF(
  @cashier_document_gift_no_exists = 0,
  'ALTER TABLE `eb_cashier_v3_recharge_gift_authority` ADD COLUMN `gift_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER `gift_id`',
  'SELECT 1'
);
PREPARE cashier_document_stmt FROM @cashier_document_sql;
EXECUTE cashier_document_stmt;
DEALLOCATE PREPARE cashier_document_stmt;

SELECT COUNT(*) INTO @cashier_document_gift_no_index_exists
FROM information_schema.statistics
WHERE table_schema = @cashier_document_db
  AND table_name = 'eb_cashier_v3_recharge_gift_authority'
  AND index_name = 'uk_tenant_gift_no';
SET @cashier_document_sql := IF(
  @cashier_document_gift_no_index_exists = 0,
  'ALTER TABLE `eb_cashier_v3_recharge_gift_authority` ADD UNIQUE KEY `uk_tenant_gift_no` (`tenant_id`,`gift_no`)',
  'SELECT 1'
);
PREPARE cashier_document_stmt FROM @cashier_document_sql;
EXECUTE cashier_document_stmt;
DEALLOCATE PREPARE cashier_document_stmt;

SELECT 'APPLY_OK' AS apply_result;
