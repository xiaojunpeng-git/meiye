SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_business_ledger_store_building` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `store_id` bigint unsigned NOT NULL DEFAULT '0', `org_id` bigint unsigned NOT NULL DEFAULT '0', `branch_name` varchar(120) NOT NULL DEFAULT '', `store_name` varchar(120) NOT NULL DEFAULT '', `province` varchar(80) NOT NULL DEFAULT '', `building_type` varchar(40) NOT NULL DEFAULT '', `contract_date` date DEFAULT NULL, `license_date` date DEFAULT NULL, `design_start_date` date DEFAULT NULL, `design_end_date` date DEFAULT NULL, `bid_start_date` date DEFAULT NULL, `bid_end_date` date DEFAULT NULL, `construction_start_date` date DEFAULT NULL, `construction_end_date` date DEFAULT NULL, `acceptance_date` date DEFAULT NULL, `acquisition_note` varchar(255) NOT NULL DEFAULT '', `recruitment_note` varchar(255) NOT NULL DEFAULT '', `opening_date` date DEFAULT NULL, `building_duration` varchar(80) NOT NULL DEFAULT '', `first_month_performance` bigint DEFAULT NULL, `second_month_performance` bigint DEFAULT NULL, `third_month_performance` bigint DEFAULT NULL, `data_json` text, `version` int unsigned NOT NULL DEFAULT '1', `request_token` varchar(128) NOT NULL DEFAULT '', `operator_id` bigint unsigned NOT NULL DEFAULT '0', `operator_name` varchar(120) NOT NULL DEFAULT '', `is_deleted` tinyint unsigned NOT NULL DEFAULT '0', `add_time` int unsigned NOT NULL DEFAULT '0', `update_time` int unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`id`), UNIQUE KEY `uk_request_token` (`request_token`), KEY `idx_scope` (`store_id`,`is_deleted`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_business_ledger_engineering_quality` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `store_id` bigint unsigned NOT NULL DEFAULT '0', `org_id` bigint unsigned NOT NULL DEFAULT '0', `branch_name` varchar(120) NOT NULL DEFAULT '', `store_name` varchar(120) NOT NULL DEFAULT '', `area` decimal(18,2) DEFAULT NULL, `construction_start_date` date DEFAULT NULL, `construction_end_date` date DEFAULT NULL, `construction_duration` varchar(80) NOT NULL DEFAULT '', `opening_date` date DEFAULT NULL, `building_duration` varchar(80) NOT NULL DEFAULT '', `hard_decoration_cost` bigint DEFAULT NULL, `soft_decoration_cost` bigint DEFAULT NULL, `unit_cost` bigint DEFAULT NULL, `total_investment` bigint DEFAULT NULL, `data_json` text, `version` int unsigned NOT NULL DEFAULT '1', `request_token` varchar(128) NOT NULL DEFAULT '', `operator_id` bigint unsigned NOT NULL DEFAULT '0', `operator_name` varchar(120) NOT NULL DEFAULT '', `is_deleted` tinyint unsigned NOT NULL DEFAULT '0', `add_time` int unsigned NOT NULL DEFAULT '0', `update_time` int unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`id`), UNIQUE KEY `uk_request_token` (`request_token`), KEY `idx_scope` (`store_id`,`is_deleted`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_business_ledger_engineering_repair` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `store_id` bigint unsigned NOT NULL DEFAULT '0', `org_id` bigint unsigned NOT NULL DEFAULT '0', `branch_name` varchar(120) NOT NULL DEFAULT '', `store_name` varchar(120) NOT NULL DEFAULT '', `report_date` date DEFAULT NULL, `reporter` varchar(120) NOT NULL DEFAULT '', `repair_requirement` text, `repair_start_date` date DEFAULT NULL, `repair_end_date` date DEFAULT NULL, `actual_duration` varchar(80) NOT NULL DEFAULT '', `repair_cost` bigint DEFAULT NULL, `data_json` text, `version` int unsigned NOT NULL DEFAULT '1', `request_token` varchar(128) NOT NULL DEFAULT '', `operator_id` bigint unsigned NOT NULL DEFAULT '0', `operator_name` varchar(120) NOT NULL DEFAULT '', `is_deleted` tinyint unsigned NOT NULL DEFAULT '0', `add_time` int unsigned NOT NULL DEFAULT '0', `update_time` int unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`id`), UNIQUE KEY `uk_request_token` (`request_token`), KEY `idx_scope` (`store_id`,`is_deleted`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_business_ledger_rent_renewal` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `store_id` bigint unsigned NOT NULL DEFAULT '0', `org_id` bigint unsigned NOT NULL DEFAULT '0', `branch_name` varchar(120) NOT NULL DEFAULT '', `store_name` varchar(120) NOT NULL DEFAULT '', `contract_start_date` date DEFAULT NULL, `contract_end_date` date DEFAULT NULL, `original_monthly_rent` bigint DEFAULT NULL, `original_annual_rent` bigint DEFAULT NULL, `rent_reduction_date` date DEFAULT NULL, `reduced_monthly_rent` bigint DEFAULT NULL, `reduced_annual_rent` bigint DEFAULT NULL, `monthly_reduction` bigint DEFAULT NULL, `annual_reduction` bigint DEFAULT NULL, `data_json` text, `version` int unsigned NOT NULL DEFAULT '1', `request_token` varchar(128) NOT NULL DEFAULT '', `operator_id` bigint unsigned NOT NULL DEFAULT '0', `operator_name` varchar(120) NOT NULL DEFAULT '', `is_deleted` tinyint unsigned NOT NULL DEFAULT '0', `add_time` int unsigned NOT NULL DEFAULT '0', `update_time` int unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`id`), UNIQUE KEY `uk_request_token` (`request_token`), KEY `idx_scope` (`store_id`,`is_deleted`,`id`), KEY `idx_contract_end` (`contract_end_date`,`is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_business_ledger_audit` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `ledger_type` varchar(40) NOT NULL, `record_id` bigint unsigned NOT NULL, `action` varchar(20) NOT NULL, `before_json` text, `after_json` text, `request_token` varchar(128) NOT NULL DEFAULT '', `operator_id` bigint unsigned NOT NULL DEFAULT '0', `operator_name` varchar(120) NOT NULL DEFAULT '', `created_at` int unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`id`), KEY `idx_record` (`ledger_type`,`record_id`,`id`), KEY `idx_token` (`request_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `eb_business_ledger_rent_reminder` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT, `ledger_id` bigint unsigned NOT NULL, `store_id` bigint unsigned NOT NULL, `reminder_month` date NOT NULL, `months_before_expiry` tinyint unsigned NOT NULL, `status` varchar(20) NOT NULL DEFAULT 'pending', `add_time` int unsigned NOT NULL DEFAULT '0', `update_time` int unsigned NOT NULL DEFAULT '0', PRIMARY KEY (`id`), UNIQUE KEY `uk_ledger_month` (`ledger_id`,`reminder_month`), KEY `idx_scope_status` (`store_id`,`status`,`reminder_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 数据菜单下的工程管理权限树（幂等；父菜单按现有“数据”节点匹配）
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT id,1,'','工程管理','admin','report','engineering-ledger','','','[]',90,1,0,1,'/report/engineering-management',CONCAT(COALESCE(NULLIF(path,''),''),CASE WHEN COALESCE(NULLIF(path,''),'') = '' THEN '' ELSE '/' END,id),1,'',1,'admin-data-engineering-management',0 FROM eb_system_menus p WHERE p.unique_auth='admin-report' AND p.type=1 AND p.is_del=0 AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-data-engineering-management' AND e.is_del=0) LIMIT 1;
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT id,1,'','建店明细','admin','report','engineering-ledger','','','[]',1,1,0,1,'/report/store-building',CONCAT(COALESCE(NULLIF(path,''),''),CASE WHEN COALESCE(NULLIF(path,''),'') = '' THEN '' ELSE '/' END,id),1,'',1,'admin-data-engineering-management-store-building',0 FROM eb_system_menus p WHERE p.unique_auth='admin-data-engineering-management' AND p.is_del=0 AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-data-engineering-management-store-building' AND e.is_del=0) LIMIT 1;
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT id,1,'','工程质量','admin','report','engineering-ledger','','','[]',2,1,0,1,'/report/engineering-quality',CONCAT(COALESCE(NULLIF(path,''),''),CASE WHEN COALESCE(NULLIF(path,''),'') = '' THEN '' ELSE '/' END,id),1,'',1,'admin-data-engineering-management-engineering-quality',0 FROM eb_system_menus p WHERE p.unique_auth='admin-data-engineering-management' AND p.is_del=0 AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-data-engineering-management-engineering-quality' AND e.is_del=0) LIMIT 1;
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT id,1,'','工程维修','admin','report','engineering-ledger','','','[]',3,1,0,1,'/report/engineering-repair',CONCAT(COALESCE(NULLIF(path,''),''),CASE WHEN COALESCE(NULLIF(path,''),'') = '' THEN '' ELSE '/' END,id),1,'',1,'admin-data-engineering-management-engineering-repair',0 FROM eb_system_menus p WHERE p.unique_auth='admin-data-engineering-management' AND p.is_del=0 AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-data-engineering-management-engineering-repair' AND e.is_del=0) LIMIT 1;
INSERT INTO eb_system_menus (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT id,1,'','降租续签','admin','report','engineering-ledger','','','[]',4,1,0,1,'/report/rent-renewal',CONCAT(COALESCE(NULLIF(path,''),''),CASE WHEN COALESCE(NULLIF(path,''),'') = '' THEN '' ELSE '/' END,id),1,'',1,'admin-data-engineering-management-rent-renewal',0 FROM eb_system_menus p WHERE p.unique_auth='admin-data-engineering-management' AND p.is_del=0 AND NOT EXISTS (SELECT 1 FROM eb_system_menus e WHERE e.unique_auth='admin-data-engineering-management-rent-renewal' AND e.is_del=0) LIMIT 1;

-- 早期执行过本升级包的环境也要被修正为侧边栏菜单节点：type=1，
-- 使用 menu_path 路由，path 只保存数据库层级路径。
UPDATE eb_system_menus parent_menu
JOIN eb_system_menus data_menu ON data_menu.unique_auth='admin-report' AND data_menu.is_del=0
SET parent_menu.pid=data_menu.id,
    parent_menu.type=1,
    parent_menu.is_show=1,
    parent_menu.is_show_path=0,
    parent_menu.access=1,
    parent_menu.auth_type=1,
    parent_menu.menu_path='/report/engineering-management',
    parent_menu.path=CONCAT(COALESCE(NULLIF(data_menu.path,''),''),CASE WHEN COALESCE(NULLIF(data_menu.path,''),'') = '' THEN '' ELSE '/' END,data_menu.id),
    parent_menu.api_url='',
    parent_menu.methods=''
WHERE parent_menu.unique_auth='admin-data-engineering-management' AND parent_menu.is_del=0;

UPDATE eb_system_menus child_menu
JOIN eb_system_menus parent_menu ON parent_menu.unique_auth='admin-data-engineering-management' AND parent_menu.is_del=0
SET child_menu.pid=parent_menu.id,
    child_menu.type=1,
    child_menu.is_show=1,
    child_menu.is_show_path=0,
    child_menu.access=1,
    child_menu.auth_type=1,
    child_menu.path=CONCAT(COALESCE(NULLIF(parent_menu.path,''),''),CASE WHEN COALESCE(NULLIF(parent_menu.path,''),'') = '' THEN '' ELSE '/' END,parent_menu.id),
    child_menu.api_url='',
    child_menu.methods=''
WHERE child_menu.unique_auth LIKE 'admin-data-engineering-management-%' AND child_menu.is_del=0;

UPDATE eb_system_menus SET menu_path='/report/store-building' WHERE unique_auth='admin-data-engineering-management-store-building' AND is_del=0;
UPDATE eb_system_menus SET menu_path='/report/engineering-quality' WHERE unique_auth='admin-data-engineering-management-engineering-quality' AND is_del=0;
UPDATE eb_system_menus SET menu_path='/report/engineering-repair' WHERE unique_auth='admin-data-engineering-management-engineering-repair' AND is_del=0;
UPDATE eb_system_menus SET menu_path='/report/rent-renewal' WHERE unique_auth='admin-data-engineering-management-rent-renewal' AND is_del=0;
UPDATE eb_system_menus SET menu_name='工程管理' WHERE unique_auth='admin-data-engineering-management' AND is_del=0;
UPDATE eb_system_menus SET menu_name='建店明细' WHERE unique_auth='admin-data-engineering-management-store-building' AND is_del=0;
UPDATE eb_system_menus SET menu_name='工程质量' WHERE unique_auth='admin-data-engineering-management-engineering-quality' AND is_del=0;
UPDATE eb_system_menus SET menu_name='工程维修' WHERE unique_auth='admin-data-engineering-management-engineering-repair' AND is_del=0;
UPDATE eb_system_menus SET menu_name='降租续签' WHERE unique_auth='admin-data-engineering-management-rent-renewal' AND is_del=0;
