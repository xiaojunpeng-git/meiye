SET NAMES utf8mb4;
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('eb_inventory_batch_movement_fact','eb_inventory_stock_request_line','eb_inventory_cross_transfer_line','eb_inventory_salon_usage_line')
  AND COLUMN_NAME='detail_remark'
ORDER BY TABLE_NAME;
