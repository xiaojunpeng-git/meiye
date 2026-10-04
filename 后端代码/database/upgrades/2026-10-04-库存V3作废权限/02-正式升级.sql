-- upgrade_key: 20261004-001-inventory-v3-void-permissions
-- 仅登记路由并复用既有仓库管理授权；供货主体与库存事务仍由服务端逐单校验。
SET NAMES utf8mb4;
INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台院装领用作废','','','','product/inventory/v3/salon-usage/:id/void','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE api_url='product/inventory/v3/salon-usage/:id/void' AND methods='POST' AND type=1 AND is_del=0);
INSERT INTO eb_system_menus
(pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台请货整单作废','','','','product/inventory/v3/hq/request/:id/void','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE api_url='product/inventory/v3/hq/request/:id/void' AND methods='POST' AND type=1 AND is_del=0);
