-- upgrade_key: 20261013-001-inventory-hq-warehouses
-- 只扩展仓库关联，不重分历史库存；旧供货仓为0的单据须核对后绑定。
SET NAMES utf8mb4;
SET @exists_column := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND column_name='supply_location_id');
SET @sql := IF(@exists_column=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD COLUMN `supply_location_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `supply_party_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_inventory_stock_request_document' AND index_name='idx_supply_location_status');
SET @sql := IF(@exists_index=0, 'ALTER TABLE `eb_inventory_stock_request_document` ADD KEY `idx_supply_location_status` (`tenant_id`,`supply_location_id`,`document_status`,`id`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists_column := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_v3_import_record' AND column_name='location_id');
SET @sql := IF(@exists_column=0, 'ALTER TABLE `eb_inventory_v3_import_record` ADD COLUMN `location_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `store_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- 设置权限不包含入出库、盘点、调拨写权限；不自动给已有岗位追加授权。
SET @inventory_parent_id := (SELECT id FROM eb_system_menus WHERE type=1 AND is_del=0 AND unique_auth='admin-stock-manage' ORDER BY id LIMIT 1);
INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT @inventory_parent_id,1,'','仓库设置','admin','','','','','[]',0,0,0,1,'/admin/stock/warehouse',CONCAT('7/',@inventory_parent_id),1,'',0,'inventory-v3-platform-warehouse-settings',0
FROM DUAL WHERE @inventory_parent_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE is_del=0 AND menu_path='/admin/stock/warehouse');
INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','总部仓库改名及启停','','','','product/inventory/v3/locations/<id>','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-settings',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE is_del=0 AND api_url='product/inventory/v3/locations/<id>' AND methods='POST');
-- 新接口只登记既有能力，不修改岗位role/rules；中间件仍核验精确功能与组织范围。
INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'',r.menu_name,'','','',r.api_url,r.methods,'[]',0,0,0,1,'','',2,'',0,r.unique_auth,0
FROM (
  SELECT '仓库设置列表' menu_name,'product/inventory/v3/warehouse-settings' api_url,'GET' methods,'inventory-v3-platform-warehouse-settings' unique_auth
  UNION ALL SELECT '确认历史请货供货仓','product/inventory/v3/hq/request/<id>/bind-supply-warehouse','POST','admin-stock-transfer-add'
  UNION ALL SELECT '平台分仓导入记录','product/inventory/v3/import','GET','inventory-v3-platform-batch-view'
  UNION ALL SELECT '平台分仓导入详情','product/inventory/v3/import/<id>/detail','GET','inventory-v3-platform-batch-view'
) r
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.is_del=0 AND m.api_url=r.api_url AND m.methods=r.methods);
SELECT 'APPLY_OK' AS apply_result;
