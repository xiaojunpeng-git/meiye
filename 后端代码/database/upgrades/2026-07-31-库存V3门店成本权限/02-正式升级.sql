-- upgrade_key: 20260731-005-inventory-v3-store-cost-permission
-- MySQL 5.6.51 compatible. Existing role grants are intentionally untouched.
SET NAMES utf8mb4;

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,2,'','库存成本查看','','','','','GET','[]',0,0,0,1,'','',1,'',0,'inventory.cost.view',0
WHERE NOT EXISTS (
  SELECT 1 FROM eb_system_menus
  WHERE type=2 AND is_del=0 AND unique_auth='inventory.cost.view'
);

SELECT 'APPLY_OK' AS apply_result;
