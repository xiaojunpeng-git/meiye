-- upgrade_key: 20260817-002-cashier-v3-business-config-platform-menu-repair
-- MySQL 5.6 compatible. This package repairs menu projections and role grants only.
SET NAMES utf8mb4;

SET @shop_parent_id := COALESCE((
  SELECT id FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='admin-setting-shop'
  ORDER BY id ASC LIMIT 1
),0);

INSERT INTO eb_system_menus
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT parent_menu.id,1,'','来源设置','','','','','','[]',0,1,0,1,
  '/admin/setting/shop/business-source',CONCAT(parent_menu.path,'/',parent_menu.id),1,'',0,'setting-shop-business-source',0
FROM eb_system_menus parent_menu
WHERE parent_menu.id=@shop_parent_id
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_menus existing_menu
    WHERE existing_menu.type=1 AND existing_menu.is_del=0
      AND existing_menu.unique_auth='setting-shop-business-source'
  );

INSERT INTO eb_system_menus
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT parent_menu.id,1,'','记账设置','','','','','','[]',0,1,0,1,
  '/admin/setting/shop/accounting',CONCAT(parent_menu.path,'/',parent_menu.id),1,'',0,'setting-shop-accounting',0
FROM eb_system_menus parent_menu
WHERE parent_menu.id=@shop_parent_id
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_menus existing_menu
    WHERE existing_menu.type=1 AND existing_menu.is_del=0
      AND existing_menu.unique_auth='setting-shop-accounting'
  );

UPDATE eb_system_menus page_menu
JOIN eb_system_menus parent_menu ON parent_menu.id=@shop_parent_id
SET page_menu.pid=parent_menu.id,
    page_menu.menu_name=CASE page_menu.unique_auth
      WHEN 'setting-shop-business-source' THEN '来源设置'
      ELSE '记账设置'
    END,
    page_menu.module='', page_menu.controller='', page_menu.action='',
    page_menu.api_url='', page_menu.methods='', page_menu.params='[]',
    page_menu.sort=0, page_menu.is_show=1, page_menu.is_show_path=0,
    page_menu.access=1,
    page_menu.menu_path=CASE page_menu.unique_auth
      WHEN 'setting-shop-business-source' THEN '/admin/setting/shop/business-source'
      ELSE '/admin/setting/shop/accounting'
    END,
    page_menu.path=CONCAT(parent_menu.path,'/',parent_menu.id),
    page_menu.auth_type=1, page_menu.header='', page_menu.is_header=0,
    page_menu.is_del=0
WHERE page_menu.type=1
  AND page_menu.unique_auth IN ('setting-shop-business-source','setting-shop-accounting');

SET @source_page_id := COALESCE((
  SELECT id FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='setting-shop-business-source'
  ORDER BY id ASC LIMIT 1
),0);
SET @accounting_page_id := COALESCE((
  SELECT id FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='setting-shop-accounting'
  ORDER BY id ASC LIMIT 1
),0);

UPDATE eb_system_role
SET rules=CONCAT(TRIM(BOTH ',' FROM COALESCE(rules,'')), IF(TRIM(BOTH ',' FROM COALESCE(rules,''))='','',','), @source_page_id)
WHERE status=1 AND @shop_parent_id>0 AND @source_page_id>0
  AND FIND_IN_SET(@shop_parent_id,rules)>0 AND FIND_IN_SET(@source_page_id,rules)=0;

UPDATE eb_system_role
SET rules=CONCAT(TRIM(BOTH ',' FROM COALESCE(rules,'')), IF(TRIM(BOTH ',' FROM COALESCE(rules,''))='','',','), @accounting_page_id)
WHERE status=1 AND @shop_parent_id>0 AND @accounting_page_id>0
  AND FIND_IN_SET(@shop_parent_id,rules)>0 AND FIND_IN_SET(@accounting_page_id,rules)=0;

SET @business_config_api_ids := (
  SELECT GROUP_CONCAT(id ORDER BY id SEPARATOR ',') FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='cashier-v3-business-config-manage'
);
SET @business_config_first_api_id := (
  SELECT MIN(id) FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='cashier-v3-business-config-manage'
);

UPDATE eb_system_role
SET rules=CONCAT(TRIM(BOTH ',' FROM COALESCE(rules,'')), IF(TRIM(BOTH ',' FROM COALESCE(rules,''))='','',','), @business_config_api_ids)
WHERE status=1 AND @shop_parent_id>0 AND @business_config_first_api_id IS NOT NULL
  AND FIND_IN_SET(@shop_parent_id,rules)>0 AND FIND_IN_SET(@business_config_first_api_id,rules)=0;

INSERT INTO eb_database_upgrade_log (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260817-002-cashier-v3-business-config-platform-menu-repair',
  '收银V3来源设置平台菜单修复',
  '2026-08-17-收银V3来源设置平台菜单修复/02-正式升级.sql',
  '', '', NOW(), 'codex', '已修复来源/记账设置菜单投影并补齐商品设置岗位权限'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20260817-002-cashier-v3-business-config-platform-menu-repair'
);

SELECT 'APPLY_OK' AS apply_result;
