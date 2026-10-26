-- upgrade_key: 20261004-001-inventory-v3-void-permissions
SELECT api_url,methods,unique_auth,is_del FROM eb_system_menus
WHERE api_url IN ('product/inventory/v3/salon-usage/:id/void','product/inventory/v3/hq/request/:id/void');
SELECT COUNT(*) AS warehouse_manage_anchor FROM eb_system_menus
WHERE unique_auth='inventory-v3-platform-warehouse-manage' AND type=1 AND is_del=0;
