-- MySQL 5.7；本表是当前实例权限元数据，不与其他客户共享。先建表，再执行03初始化器。
CREATE TABLE IF NOT EXISTS `eb_store_v3_permission_catalog` (
 `id` int unsigned NOT NULL,
 `rules_json` mediumtext NOT NULL,
 `revision` int unsigned NOT NULL DEFAULT 1,
 `updated_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_store_v3_permission_audit` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `operator_id` int unsigned NOT NULL DEFAULT 0,
 `action` varchar(40) NOT NULL,
 `before_json` mediumtext NOT NULL,
 `after_json` mediumtext NOT NULL,
 `created_at` int unsigned NOT NULL,
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 新增独立导航，沿用已有权限规则管理资格，不写任何 platform/mobile 岗位记录。
INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT p.pid,1,'','门店端权限规则','admin','','','','[]','[]',p.sort-1,1,0,1,
 '/admin/setting/store-permissions',p.path,1,'',0,'setting-store-permissions',0
FROM eb_system_menus p WHERE p.unique_auth='setting-system-menus' AND p.type=1 AND p.is_del=0
AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='setting-store-permissions' AND e.is_del=0) LIMIT 1;
