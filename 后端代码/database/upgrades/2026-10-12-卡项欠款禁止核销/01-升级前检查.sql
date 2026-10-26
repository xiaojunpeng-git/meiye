SELECT COUNT(*) AS existing_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_store_product'
  AND COLUMN_NAME = 'block_writeoff_on_debt';
