-- upgrade_key: 20260731-002-cashier-v3-inventory-submenu-permissions
-- MySQL 5.6.51 compatible. The records are hidden role capabilities, not legacy UI menus.
SET NAMES utf8mb4;

INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：首页','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/overview',1,'',0,'cashier-inventory-overview',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-overview' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：入库','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/inbound',1,'',0,'cashier-inventory-inbound',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-inbound' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：出库','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/outbound',1,'',0,'cashier-inventory-outbound',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-outbound' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：查询','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/stock',1,'',0,'cashier-inventory-stock',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-stock' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：盘点','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/count',1,'',0,'cashier-inventory-count',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-count' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：出入库记录','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/movement',1,'',0,'cashier-inventory-movement',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-movement' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：统计','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/statistics',1,'',0,'cashier-inventory-statistics',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-statistics' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：请货','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/request',1,'',0,'cashier-inventory-request',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-request' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：调拨','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/transfer',1,'',0,'cashier-inventory-transfer',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-transfer' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：院装','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/usage',1,'',0,'cashier-inventory-usage',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-usage' AND is_del=0);
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,3,'','库存：导入','','','','','GET','[]',0,0,0,1,'','/cashier/inventory/import',1,'',0,'cashier-inventory-import',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=3 AND unique_auth='cashier-inventory-import' AND is_del=0);
SELECT 'APPLY_OK' AS apply_result;
