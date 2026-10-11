-- Product configuration only. Preserve all historical batches, dates, balances and facts.
SET @expiry_column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_store_product' AND COLUMN_NAME = 'no_expiry_management');
SET @expiry_ddl = IF(@expiry_column_exists = 0,
  'ALTER TABLE `eb_store_product` ADD COLUMN `no_expiry_management` tinyint unsigned NOT NULL DEFAULT 0 COMMENT ''免录批次及有效期，历史批次不变''',
  'SELECT 1');
PREPARE expiry_statement FROM @expiry_ddl;
EXECUTE expiry_statement;
DEALLOCATE PREPARE expiry_statement;
