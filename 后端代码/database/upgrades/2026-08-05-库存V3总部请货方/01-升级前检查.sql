-- upgrade_key: 20260805-002-inventory-v3-hq-request-party
SET NAMES utf8mb4;

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema=DATABASE() AND table_name IN ('eb_inventory_stock_request_document','eb_inventory_cross_transfer_document');

SELECT COUNT(*) AS legacy_rows_without_request_party
FROM eb_inventory_stock_request_document
WHERE store_id > 0;
