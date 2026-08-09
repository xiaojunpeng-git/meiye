-- upgrade_key: 20260805-001-cashier-v3-service-document-no
-- Run 01 before and 03 after this script. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @service_no_db := DATABASE();

SELECT COUNT(*) INTO @service_no_column_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@service_no_db
  AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact'
  AND COLUMN_NAME='service_record_no';
SET @service_no_add_column := IF(@service_no_column_exists=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `service_record_no` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER `service_fact_id`',
  'SELECT 1');
PREPARE service_no_statement FROM @service_no_add_column;
EXECUTE service_no_statement;
DEALLOCATE PREPARE service_no_statement;

SELECT COUNT(*) INTO @service_no_index_exists
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@service_no_db
  AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact'
  AND INDEX_NAME='uk_tenant_service_record_no';
SET @service_no_add_index := IF(@service_no_index_exists=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD UNIQUE KEY `uk_tenant_service_record_no` (`tenant_id`,`service_record_no`)',
  'SELECT 1');
PREPARE service_no_statement FROM @service_no_add_index;
EXECUTE service_no_statement;
DEALLOCATE PREPARE service_no_statement;

-- Historical ESF rows intentionally remain NULL. New successful writes populate
-- this customer-visible number in the same transaction as the service fact.
SELECT 'APPLY_OK' AS apply_result;
