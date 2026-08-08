-- upgrade_key: 20260803-004-inventory-v3-platform-hq-catalog
SET NAMES utf8mb4;

SELECT COUNT(*) AS hq_catalog_and_inbound_routes
FROM `eb_system_menus`
WHERE `type`=1 AND `is_del`=0
  AND `unique_auth`='inventory-v3-platform-warehouse-manage'
  AND (
    (`api_url`='product/inventory/v3/hq/catalog' AND `methods`='GET')
    OR (`api_url`='product/inventory/v3/hq/inbound' AND `methods`='GET')
  );

SELECT COUNT(*) AS upgrade_log_exists
FROM `eb_database_upgrade_log`
WHERE `upgrade_key`='20260803-004-inventory-v3-platform-hq-catalog';
