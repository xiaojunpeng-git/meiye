-- MySQL 5.7；仅创建当前实例规则目录、审计和平台菜单，不改岗位授权或业务事实。可重复执行。
CREATE TABLE IF NOT EXISTS `eb_merchant_data_permission_catalog` (
 `id` int unsigned NOT NULL,
 `rules_json` mediumtext NOT NULL,
 `revision` int unsigned NOT NULL DEFAULT 1,
 `updated_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_merchant_data_permission_audit` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `operator_id` int unsigned NOT NULL DEFAULT 0,
 `before_json` mediumtext NOT NULL,
 `after_json` mediumtext NOT NULL,
 `created_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 新增独立导航，沿用已有权限规则管理资格，不写任何 platform/mobile 岗位记录。
INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.pid,1,'','商家端权限规则','admin','','','','[]','[]',p.sort-1,1,0,1,
 '/admin/setting/merchant-permissions',p.path,1,p.header,0,'setting-merchant-permissions',0
FROM eb_system_menus p WHERE p.unique_auth='setting-system-menus' AND p.type=1 AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='setting-merchant-permissions' AND e.is_del=0) LIMIT 1;

-- 仅纠正本入口导航元数据；不改任何角色勾选。header必须继承设置分组，不能落入空header的全部菜单。
UPDATE eb_system_menus m
JOIN eb_system_menus p ON p.unique_auth='setting-system-menus' AND p.type=1 AND p.is_del=0
SET m.pid=p.pid,m.path=p.path,m.header=p.header,m.module=p.module
WHERE m.unique_auth='setting-merchant-permissions' AND m.type=1 AND m.is_del=0;

INSERT INTO eb_merchant_data_permission_catalog(id,rules_json,revision,updated_at) VALUES(1,'[]',1,UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=id;
