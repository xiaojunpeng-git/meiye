<?php
/** 显式CLI升级器；先备份本实例岗位/规则表，初始化与审计可重复执行，不在页面读取时迁移。 */
require dirname(__DIR__,3).'/vendor/autoload.php';
(new think\App(dirname(__DIR__,3).'/'))->initialize();
(new app\services\organization\StorePermissionCatalogServices())->initializeLegacyCatalog(__DIR__.'/02-正式升级.sql');
(new app\services\organization\StorePermissionCatalogServices())->initializeNavigationGroups();
(new app\services\organization\StorePermissionCatalogServices())->initializeOrderNavigation();
(new app\services\organization\StorePermissionCatalogServices())->initializeNavigationItems();
echo "PERMISSION_CATALOG_READY\n";
