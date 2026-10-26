-- upgrade_key: 20261004-001-inventory-v3-void-permissions
SELECT api_url,methods,unique_auth,COUNT(*) AS registrations FROM eb_system_menus
WHERE api_url IN ('product/inventory/v3/salon-usage/:id/void','product/inventory/v3/hq/request/:id/void')
AND methods='POST' AND type=1 AND auth_type=2 AND is_del=0
GROUP BY api_url,methods,unique_auth;
