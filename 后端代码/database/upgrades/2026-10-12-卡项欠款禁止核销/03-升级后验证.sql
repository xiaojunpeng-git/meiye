SELECT COUNT(*) AS expected_one_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_store_product'
  AND COLUMN_NAME = 'block_writeoff_on_debt';

SELECT COUNT(*) AS expected_zero_invalid_values
FROM `eb_store_product`
WHERE `block_writeoff_on_debt` NOT IN (0, 1)
   OR (`product_type` <> 5 AND `block_writeoff_on_debt` <> 0);
