-- upgrade_key: 20260818-003-cashier-v3-gift-product-claim-outbound
-- MySQL 5.6 compatible, repeatable and additive only.
SET NAMES utf8mb4;
SET @gift_claim_db := DATABASE();

SELECT COUNT(*) INTO @gift_claim_source_kind
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@gift_claim_db AND TABLE_NAME='eb_cashier_v3_presale_claimable_line' AND COLUMN_NAME='source_kind';
SET @gift_claim_sql := IF(@gift_claim_source_kind=0,
  "ALTER TABLE `eb_cashier_v3_presale_claimable_line` ADD COLUMN `source_kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PRESALE' AFTER `claimable_line_id`",
  'SELECT 1');
PREPARE gift_claim_stmt FROM @gift_claim_sql; EXECUTE gift_claim_stmt; DEALLOCATE PREPARE gift_claim_stmt;

SELECT COUNT(*) INTO @gift_claim_gift_id
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@gift_claim_db AND TABLE_NAME='eb_cashier_v3_presale_claimable_line' AND COLUMN_NAME='gift_id';
SET @gift_claim_sql := IF(@gift_claim_gift_id=0,
  "ALTER TABLE `eb_cashier_v3_presale_claimable_line` ADD COLUMN `gift_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER `source_kind`",
  'SELECT 1');
PREPARE gift_claim_stmt FROM @gift_claim_sql; EXECUTE gift_claim_stmt; DEALLOCATE PREPARE gift_claim_stmt;

SELECT COUNT(*) INTO @gift_claim_gift_item_id
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@gift_claim_db AND TABLE_NAME='eb_cashier_v3_presale_claimable_line' AND COLUMN_NAME='gift_item_id';
SET @gift_claim_sql := IF(@gift_claim_gift_item_id=0,
  "ALTER TABLE `eb_cashier_v3_presale_claimable_line` ADD COLUMN `gift_item_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER `gift_id`",
  'SELECT 1');
PREPARE gift_claim_stmt FROM @gift_claim_sql; EXECUTE gift_claim_stmt; DEALLOCATE PREPARE gift_claim_stmt;

SELECT COUNT(*) INTO @gift_claim_line_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@gift_claim_db AND TABLE_NAME='eb_cashier_v3_presale_claimable_line' AND INDEX_NAME='idx_scope_source_status_date';
SET @gift_claim_sql := IF(@gift_claim_line_index=0,
  'ALTER TABLE `eb_cashier_v3_presale_claimable_line` ADD KEY `idx_scope_source_status_date` (`tenant_id`,`store_id`,`source_kind`,`claim_status`,`business_date`,`id`)',
  'SELECT 1');
PREPARE gift_claim_stmt FROM @gift_claim_sql; EXECUTE gift_claim_stmt; DEALLOCATE PREPARE gift_claim_stmt;

SELECT COUNT(*) INTO @gift_claim_item_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@gift_claim_db AND TABLE_NAME='eb_cashier_v3_presale_claimable_line' AND INDEX_NAME='idx_gift_item';
SET @gift_claim_sql := IF(@gift_claim_item_index=0,
  'ALTER TABLE `eb_cashier_v3_presale_claimable_line` ADD KEY `idx_gift_item` (`tenant_id`,`gift_item_id`,`id`)',
  'SELECT 1');
PREPARE gift_claim_stmt FROM @gift_claim_sql; EXECUTE gift_claim_stmt; DEALLOCATE PREPARE gift_claim_stmt;

SELECT COUNT(*) INTO @gift_claim_document_source
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@gift_claim_db AND TABLE_NAME='eb_cashier_v3_presale_claim' AND COLUMN_NAME='source_kind';
SET @gift_claim_sql := IF(@gift_claim_document_source=0,
  "ALTER TABLE `eb_cashier_v3_presale_claim` ADD COLUMN `source_kind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PRESALE' AFTER `claimable_line_id`",
  'SELECT 1');
PREPARE gift_claim_stmt FROM @gift_claim_sql; EXECUTE gift_claim_stmt; DEALLOCATE PREPARE gift_claim_stmt;

SELECT 'APPLY_OK' AS apply_result;
