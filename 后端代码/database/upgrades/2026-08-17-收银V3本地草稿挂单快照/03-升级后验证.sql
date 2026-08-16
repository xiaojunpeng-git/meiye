SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_hang_order'
  AND COLUMN_NAME='local_draft_snapshot_json';
