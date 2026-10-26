-- Expect both columns and the index; existing card/document rows retain an empty catalog identity.
SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_adjustment'
  AND COLUMN_NAME IN ('catalog_item_id', 'catalog_kind');
SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_adjustment'
  AND INDEX_NAME = 'idx_catalog_item' ORDER BY SEQ_IN_INDEX;
SELECT COUNT(*) AS legacy_rows_with_catalog_identity
FROM eb_cashier_v3_refund_adjustment
WHERE target_type IN ('card', 'document') AND (catalog_item_id <> 0 OR catalog_kind <> '');
