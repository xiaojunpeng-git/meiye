-- upgrade_key: 20260806-001-inventory-v3-requester-and-hq-count
SET NAMES utf8mb4;

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema=DATABASE()
  AND table_name IN ('eb_inventory_stock_request_document','eb_inventory_stock_request_revision','eb_inventory_stock_count_document');

SELECT column_name
FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document'
  AND column_name IN ('requester_name_snapshot','operator_name_snapshot');
