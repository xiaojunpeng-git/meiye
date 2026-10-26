-- upgrade_key: 20260731-001-inventory-v3-platform-access-scope
-- Run 01 before and 03 after this script. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;

-- Cost is a field-level capability, so it deliberately has no callable API
-- route.  It can still be granted independently in the role permission tree.
INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台库存成本查看','','','','','GET','[]',0,0,0,1,'','',1,'',0,'inventory-v3-platform-batch-cost',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND unique_auth='inventory-v3-platform-batch-cost' AND is_del=0);

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台库存查看（仓库）','','','','product/inventory/v3/locations','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND api_url='product/inventory/v3/locations' AND methods='GET' AND is_del=0);

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台库存查看（批次）','','','','product/inventory/v3/batch-stock','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND api_url='product/inventory/v3/batch-stock' AND methods='GET' AND is_del=0);

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台库存查看（统一查询）','','','','product/inventory/v3/unified-query/batch-stock','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-view',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND api_url='product/inventory/v3/unified-query/batch-stock' AND methods='GET' AND is_del=0);

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台库存查询管理（能力）','','','','product/inventory/v3/unified-query/capabilities','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-query-manage',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND api_url='product/inventory/v3/unified-query/capabilities' AND methods='GET' AND is_del=0);

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台库存查询管理（命令）','','','','product/inventory/v3/unified-query/commands','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-query-manage',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND api_url='product/inventory/v3/unified-query/commands' AND methods='POST' AND is_del=0);

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台库存导出','','','','product/inventory/v3/unified-query/export-task/:taskNo','GET','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-batch-export',0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE type=1 AND api_url='product/inventory/v3/unified-query/export-task/:taskNo' AND methods='GET' AND is_del=0);

SELECT 'APPLY_OK' AS apply_result;
