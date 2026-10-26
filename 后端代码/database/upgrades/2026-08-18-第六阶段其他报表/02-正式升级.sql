-- upgrade_key: 20260818-001-phase-six-reports
-- MySQL 5.6 compatible and re-runnable.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_beautician_establishment` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `establishment_count` int(10) unsigned NOT NULL DEFAULT 0,
  `version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `updated_by` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_by_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_store` (`tenant_id`,`store_id`),
  KEY `idx_tenant_store_updated` (`tenant_id`,`store_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='current beautician establishment per store; no business history';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_report_beautician_establishment_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `before_count` int(10) unsigned NULL,
  `after_count` int(10) unsigned NOT NULL,
  `version` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `occurred_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_store_time` (`tenant_id`,`store_id`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='audit for current beautician establishment edits';

SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='project_count')=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `project_count` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `quantity`',
  'SELECT ''project_count_already_present'' AS apply_result');
PREPARE phase_six_stmt FROM @sql; EXECUTE phase_six_stmt; DEALLOCATE PREPARE phase_six_stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_employee' AND COLUMN_NAME='mentor_employee_id')=0,
  'ALTER TABLE `eb_employee` ADD COLUMN `mentor_employee_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT ''教培师傅员工ID''',
  'SELECT ''mentor_employee_id_already_present'' AS apply_result');
PREPARE phase_six_stmt FROM @sql; EXECUTE phase_six_stmt; DEALLOCATE PREPARE phase_six_stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_system_store_staff' AND COLUMN_NAME='mentor_employee_id')=0,
  'ALTER TABLE `eb_system_store_staff` ADD COLUMN `mentor_employee_id` bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT ''门店任职师傅员工ID''',
  'SELECT ''staff_mentor_employee_id_already_present'' AS apply_result');
PREPARE phase_six_stmt FROM @sql; EXECUTE phase_six_stmt; DEALLOCATE PREPARE phase_six_stmt;

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT data_menu.id,1,'ios-stats-outline','其他报表','admin','','','','[]','[]',1,1,0,1,
       '/report/other-reports',CAST(data_menu.id AS CHAR),1,'',0,'admin-report-other-reports',0
FROM eb_system_menus data_menu
WHERE data_menu.unique_auth='admin-report' AND data_menu.type=1 AND data_menu.is_del=0
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth='admin-report-other-reports' AND m.is_del=0);

INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT parent.id,1,'ios-list-box-outline',r.menu_name,'admin','','','','[]','[]',r.sort_order,1,0,1,
       CONCAT('/report/other-reports/',r.report_code),CONCAT(data_menu.id,'/',parent.id),1,'',0,
       CONCAT('admin-report-phase-six-',r.report_code),0
FROM (SELECT 1 sort_order,'phase_six_garden_item_analysis' report_code,'花园品项分析表' menu_name UNION ALL
      SELECT 2,'phase_six_monthly_featured_item','月主推数据统计表' UNION ALL
      SELECT 3,'phase_six_headquarters_acquisition','总部拓客数据统计表' UNION ALL
      SELECT 4,'phase_six_other_multi_payment','其他多收款业绩表' UNION ALL
      SELECT 5,'phase_six_salary_summary','员工薪资汇总月报表' UNION ALL
      SELECT 6,'phase_six_salary_detail','员工薪资明细月报表' UNION ALL
      SELECT 7,'phase_six_training_employee','教培员工需求统计表' UNION ALL
      SELECT 8,'phase_six_acquisition_source','拓客部门客户来源数据分析表' UNION ALL
      SELECT 9,'phase_six_human_store_health','人力-院店健康报表') r
JOIN eb_system_menus parent ON parent.unique_auth='admin-report-other-reports' AND parent.type=1 AND parent.is_del=0
JOIN eb_system_menus data_menu ON data_menu.id=parent.pid AND data_menu.unique_auth='admin-report' AND data_menu.is_del=0
WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus m WHERE m.unique_auth=CONCAT('admin-report-phase-six-',r.report_code) AND m.is_del=0);

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260818-001-phase-six-reports','第六阶段九张其他报表','2026-08-18-第六阶段其他报表/02-正式升级.sql','','',NOW(),'codex-local','menu, staffing, project_count and mentor field'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log WHERE upgrade_key='20260818-001-phase-six-reports');

SELECT 'APPLY_OK' AS apply_result;
