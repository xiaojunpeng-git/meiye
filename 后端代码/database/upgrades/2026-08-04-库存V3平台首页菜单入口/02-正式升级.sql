-- upgrade_key: 20260804-002-inventory-v3-platform-home-menu
SET NAMES utf8mb4;

SET @inventory_parent_id := (
  SELECT `id` FROM `eb_system_menus`
  WHERE `type`=1 AND `is_del`=0 AND `unique_auth`='admin-stock-manage'
  ORDER BY `id` LIMIT 1
);

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @inventory_parent_id,1,'','库存首页','admin','','','','','[]',99,1,0,1,
       '/admin/stock/manage/home',CONCAT('7/', @inventory_parent_id),1,'',0,'admin-stock-manage',0
FROM DUAL
WHERE @inventory_parent_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `eb_system_menus`
    WHERE `type`=1 AND `is_del`=0 AND `menu_path`='/admin/stock/manage/home'
  );

