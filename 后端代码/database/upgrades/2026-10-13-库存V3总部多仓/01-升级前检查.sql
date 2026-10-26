-- 只读检查；输出需登记到对应实例执行记录。
SELECT DATABASE() AS target_database, VERSION() AS mysql_version;
SELECT COUNT(*) stock_rows, COALESCE(SUM(available_quantity_units),0) stock_units FROM eb_inventory_stock;
SELECT COUNT(*) movement_rows FROM eb_inventory_batch_movement_fact;
SELECT tenant_id,location_type,owner_id,COUNT(*) default_count
FROM eb_inventory_location WHERE is_default=1 GROUP BY tenant_id,location_type,owner_id HAVING COUNT(*)>1;
SELECT d.id,d.request_no,d.supply_party_type,d.supply_party_id,d.acceptance_party_name_snapshot,d.document_status
FROM eb_inventory_stock_request_document d WHERE d.supply_party_type='HQ' AND d.document_status IN ('APPLIED','PARTIAL');
SELECT d.id,d.request_no FROM eb_inventory_stock_request_document d
LEFT JOIN eb_inventory_location l ON l.id=d.location_id WHERE l.id IS NULL;
