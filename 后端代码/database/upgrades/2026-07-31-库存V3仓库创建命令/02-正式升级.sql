-- upgrade_key: 20260731-004-inventory-v3-warehouse-create-command
-- Run 01 before and 03 after this script. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @iwc_db := DATABASE();

SET @iwc_creator_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@iwc_db AND TABLE_NAME='eb_inventory_location' AND COLUMN_NAME='created_by_admin_id');
SET @iwc_sql := IF(@iwc_creator_column=0,
  'ALTER TABLE `eb_inventory_location` ADD COLUMN `created_by_admin_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `version`',
  'SELECT ''skip inventory_location.created_by_admin_id''');
PREPARE iwc_stmt FROM @iwc_sql; EXECUTE iwc_stmt; DEALLOCATE PREPARE iwc_stmt;

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT 0,1,'','平台库存仓库管理','','','','product/inventory/v3/locations','POST','[]',0,0,0,1,'','',2,'',0,'inventory-v3-platform-warehouse-manage',0
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_system_menus
  WHERE type=1 AND api_url='product/inventory/v3/locations' AND methods='POST' AND is_del=0
);

SELECT 'APPLY_OK' AS apply_result;
