-- 推广人统计表为独立后台菜单权限；仅注册入口，不自动扩张既有岗位权限。
INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.id,1,'','推广人统计表','admin','marketing.store_combination','promoter_report','','[]','[]',3,1,0,1,
 '/marketing/store_combination/promoter_report',CONCAT_WS('/',NULLIF(p.path,''),p.id),1,'marketing',0,'marketing-store_combination-promoter_report',0
FROM eb_system_menus p
WHERE p.unique_auth='marketing-store_combination-index' AND p.is_del=0
 AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='marketing-store_combination-promoter_report' AND e.is_del=0)
 AND (SELECT COUNT(*) FROM eb_system_menus c WHERE c.unique_auth='marketing-store_combination-index' AND c.is_del=0)=1;
