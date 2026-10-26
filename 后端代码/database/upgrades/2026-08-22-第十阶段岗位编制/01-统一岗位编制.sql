-- upgrade_key: 20260822-001-staffing-quota
-- MySQL 5.6 compatible and re-runnable.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_staffing_quota` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_type` varchar(16) NOT NULL COMMENT 'organization or store',
  `scope_id` bigint(20) unsigned NOT NULL,
  `position_id` bigint(20) unsigned NOT NULL,
  `quota_count` int(10) unsigned NOT NULL DEFAULT 0,
  `version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `updated_by` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_by_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_scope_position` (`tenant_id`,`scope_type`,`scope_id`,`position_id`),
  KEY `idx_tenant_scope` (`tenant_id`,`scope_type`,`scope_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='统一岗位编制当前配置';

CREATE TABLE IF NOT EXISTS `eb_staffing_quota_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_type` varchar(16) NOT NULL,
  `scope_id` bigint(20) unsigned NOT NULL,
  `position_id` bigint(20) unsigned NOT NULL,
  `before_count` int(10) unsigned NULL,
  `after_count` int(10) unsigned NOT NULL,
  `version` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `request_id` varchar(64) NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_scope_time` (`tenant_id`,`scope_type`,`scope_id`,`occurred_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='统一岗位编制修改审计';

CREATE TABLE IF NOT EXISTS `eb_staffing_quota_request` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` varchar(64) NOT NULL,
  `response_json` text NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_request` (`tenant_id`,`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='统一岗位编制批量保存幂等请求';

-- 旧美容师编制只迁移一次，新表成为唯一维护来源。
INSERT INTO `eb_staffing_quota`
(`tenant_id`,`scope_type`,`scope_id`,`position_id`,`quota_count`,`version`,`updated_by`,`updated_by_name_snapshot`,`created_at`,`updated_at`)
SELECT old.tenant_id,'store',old.store_id,p.id,old.establishment_count,old.version,old.updated_by,old.updated_by_name_snapshot,old.created_at,old.updated_at
FROM `eb_cashier_v3_report_beautician_establishment` old
JOIN `eb_position` p ON p.name='美容师' AND p.status=1
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_staffing_quota` q
  WHERE q.tenant_id=old.tenant_id AND q.scope_type='store' AND q.scope_id=old.store_id AND q.position_id=p.id
);

-- 岗位编制作为组织架构下的独立功能，功能授权即拥有整表编辑能力。
SET @organization_parent_id := (SELECT id FROM `eb_system_menus`
    WHERE `unique_auth`='admin-organizational-structure' AND `is_del`=0 ORDER BY id LIMIT 1);
INSERT INTO `eb_system_menus`
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @organization_parent_id,1,'ios-grid-outline','岗位编制','admin','','','','','[]',99,1,0,1,
       '/admin/report/staffing',CONCAT('7/',@organization_parent_id),1,'',0,'admin-organization-staffing',0
FROM DUAL WHERE @organization_parent_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `unique_auth`='admin-organization-staffing' AND `is_del`=0);
SET @staffing_menu_id := (SELECT id FROM `eb_system_menus` WHERE `unique_auth`='admin-organization-staffing' AND `is_del`=0 ORDER BY id LIMIT 1);
UPDATE `eb_system_role` SET `rules`=CONCAT_WS(',',NULLIF(TRIM(BOTH ',' FROM COALESCE(`rules`,'')),''),IF(@staffing_menu_id IS NULL OR FIND_IN_SET(@staffing_menu_id,`rules`),NULL,@staffing_menu_id))
WHERE `status`=1 AND @staffing_menu_id IS NOT NULL AND (`level`=0 OR FIND_IN_SET(@organization_parent_id,`rules`));

INSERT INTO `eb_database_upgrade_log`
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260822-001-staffing-quota','统一岗位编制功能','2026-08-22-第十阶段岗位编制/01-统一岗位编制.sql','','',NOW(),'codex-local','统一组织/门店岗位编制表，迁移旧美容师编制'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_database_upgrade_log` WHERE upgrade_key='20260822-001-staffing-quota');
