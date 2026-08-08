-- upgrade_key: 20260805-002-inventory-v3-hq-request-party
SET NAMES utf8mb4;

SELECT COUNT(*) AS request_party_columns
FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document'
  AND column_name IN ('request_party_type','request_party_id','request_party_name_snapshot');

SELECT COUNT(*) AS legacy_store_rows_kept_unchanged
FROM eb_inventory_stock_request_document
WHERE store_id > 0 AND request_party_id=0;

SELECT `api_url`,`methods`,`unique_auth`,`is_del`
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0 AND `api_url` IN (
  'product/inventory/v3/hq/request/counterparties',
  'product/inventory/v3/hq/request/apply',
  'product/inventory/v3/hq/request',
  'product/inventory/v3/hq/request/:id/detail',
  'product/inventory/v3/unified-query/operational'
)
ORDER BY `api_url`,`methods`;
