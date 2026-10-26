-- 与升级前库存、流水数字逐项比较；不以菜单可见替代业务验收。
SELECT COUNT(*) stock_rows, COALESCE(SUM(available_quantity_units),0) stock_units FROM eb_inventory_stock;
SELECT COUNT(*) movement_rows FROM eb_inventory_batch_movement_fact;
SHOW COLUMNS FROM eb_inventory_stock_request_document LIKE 'supply_location_id';
SHOW INDEX FROM eb_inventory_stock_request_document WHERE Key_name='idx_supply_location_status';
SHOW COLUMNS FROM eb_inventory_v3_import_record LIKE 'location_id';
SELECT id,menu_name,api_url,methods,unique_auth FROM eb_system_menus
WHERE is_del=0 AND (unique_auth='inventory-v3-platform-warehouse-settings' OR api_url LIKE '%bind-supply-warehouse');
SELECT id,request_no,location_id,supply_location_id,document_status FROM eb_inventory_stock_request_document
WHERE supply_party_type='HQ' AND supply_location_id=0 AND document_status IN ('APPLIED','PARTIAL');
SELECT d.id,d.request_no FROM eb_inventory_stock_request_document d
LEFT JOIN eb_inventory_location l ON l.id=d.supply_location_id
WHERE d.supply_location_id>0 AND (l.id IS NULL OR l.tenant_id<>d.tenant_id);
