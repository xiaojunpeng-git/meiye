SET NAMES utf8mb4;

-- 将岗位策略从组织工作台顶部操作区迁移为组织架构下的独立菜单。
SET @organization_parent_id := (SELECT id FROM eb_system_menus
    WHERE unique_auth='admin-organizational-structure' AND is_del=0
    ORDER BY id LIMIT 1);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @organization_parent_id, 1, 'md-briefcase', '岗位策略', 'admin', '', '', '', '', '[]', 98, 1, 0, 1,
       '/admin/store/region/job-positions',
       CONCAT('7/', @organization_parent_id), 1, '', 0,
       'admin-store-region-job_positions', 0
FROM DUAL
WHERE @organization_parent_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE unique_auth='admin-store-region-job_positions' AND is_del=0);

SET @job_position_menu_id := (SELECT id FROM eb_system_menus
    WHERE unique_auth='admin-store-region-job_positions' AND is_del=0
    ORDER BY id LIMIT 1);

-- Vue 2 动态菜单按角色 rules 过滤；保持现有组织架构可见角色与新子菜单一致。
UPDATE eb_system_role
SET rules = CONCAT_WS(',',
    NULLIF(TRIM(BOTH ',' FROM COALESCE(rules,'')),''),
    IF(@job_position_menu_id IS NULL OR FIND_IN_SET(@job_position_menu_id, rules), NULL, @job_position_menu_id)
)
WHERE status=1 AND @job_position_menu_id IS NOT NULL
  AND (FIND_IN_SET(@organization_parent_id, rules) OR level=0);

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260821-004-organization-job-position-menu',
       '岗位策略独立功能',
       '2026-08-21-岗位策略独立功能/01-岗位策略独立菜单.sql',
       '', '', NOW(), 'codex-local',
       'moved job position policy from organization workspace action area to an organization architecture child menu'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log
                  WHERE upgrade_key='20260821-004-organization-job-position-menu');

COMMIT;
