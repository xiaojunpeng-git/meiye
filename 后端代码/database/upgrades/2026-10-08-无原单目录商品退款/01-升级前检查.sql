-- Expect zero new columns/indexes before applying the migration; stop if they already exist.
SELECT COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_adjustment'
  AND COLUMN_NAME IN ('catalog_item_id', 'catalog_kind');
SELECT INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_adjustment'
  AND INDEX_NAME = 'idx_catalog_item';
