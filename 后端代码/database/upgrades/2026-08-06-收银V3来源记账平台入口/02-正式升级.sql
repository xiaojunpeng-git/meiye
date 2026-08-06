-- upgrade_key: 20260806-003-cashier-v3-business-config-platform-menu
-- MySQL 5.6 compatible. This package adds platform menu projections only.
SET NAMES utf8mb4;

SET @parent_id := COALESCE((
  SELECT id FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='admin-setting-shop'
  ORDER BY id LIMIT 1
),0);

INSERT INTO eb_system_menus
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @parent_id,1,'','来源设置','','','','','', '[]',0,1,0,1,CONCAT('12/',@parent_id),'/admin/setting/shop/business-source',1,'',0,'setting-shop-business-source',0
FROM DUAL
WHERE @parent_id>0
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE is_del=0 AND unique_auth='setting-shop-business-source');

INSERT INTO eb_system_menus
  (`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @parent_id,1,'','记账设置','','','','','', '[]',0,1,0,1,CONCAT('12/',@parent_id),'/admin/setting/shop/accounting',1,'',0,'setting-shop-accounting',0
FROM DUAL
WHERE @parent_id>0
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE is_del=0 AND unique_auth='setting-shop-accounting');

SET @source_page_id := COALESCE((SELECT id FROM eb_system_menus WHERE is_del=0 AND unique_auth='setting-shop-business-source' ORDER BY id LIMIT 1),0);
SET @accounting_page_id := COALESCE((SELECT id FROM eb_system_menus WHERE is_del=0 AND unique_auth='setting-shop-accounting' ORDER BY id LIMIT 1),0);

UPDATE eb_system_role
SET rules=CONCAT(TRIM(BOTH ',' FROM COALESCE(rules,'')), IF(TRIM(BOTH ',' FROM COALESCE(rules,''))='','',','), @source_page_id)
WHERE status=1 AND @parent_id>0 AND @source_page_id>0
  AND FIND_IN_SET(@parent_id,rules)>0 AND FIND_IN_SET(@source_page_id,rules)=0;

UPDATE eb_system_role
SET rules=CONCAT(TRIM(BOTH ',' FROM COALESCE(rules,'')), IF(TRIM(BOTH ',' FROM COALESCE(rules,''))='','',','), @accounting_page_id)
WHERE status=1 AND @parent_id>0 AND @accounting_page_id>0
  AND FIND_IN_SET(@parent_id,rules)>0 AND FIND_IN_SET(@accounting_page_id,rules)=0;

SELECT 'APPLY_OK' AS apply_result;
