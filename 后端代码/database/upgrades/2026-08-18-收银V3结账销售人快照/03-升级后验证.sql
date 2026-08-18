SELECT COUNT(*) AS salespeople_snapshot_column_ready
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_checkout_line_draft'
  AND COLUMN_NAME = 'salespeople_snapshot_json'
  AND DATA_TYPE IN ('mediumtext', 'longtext', 'text');
