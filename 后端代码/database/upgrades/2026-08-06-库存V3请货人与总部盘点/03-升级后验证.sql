-- upgrade_key: 20260806-001-inventory-v3-requester-and-hq-count
SET NAMES utf8mb4;

SELECT COUNT(*) AS requester_snapshot_columns
FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document'
  AND column_name IN ('requester_name_snapshot','operator_name_snapshot');

SELECT `api_url`,`methods`,`unique_auth`,`is_del`
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0 AND (
  (`api_url`='product/inventory/v3/hq/count/confirm' AND `methods`='POST')
  OR (`api_url` IN ('product/inventory/v3/catalog/barcode','product/inventory/v3/request/requester','product/inventory/v3/hq/catalog/barcode','product/inventory/v3/hq/request/requester') AND `methods`='GET')
)
ORDER BY `api_url`,`methods`;
