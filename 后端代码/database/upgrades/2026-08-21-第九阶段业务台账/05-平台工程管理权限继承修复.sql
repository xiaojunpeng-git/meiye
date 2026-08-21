SET NAMES utf8mb4;

-- Existing platform roles that can open the group management dashboard can
-- also open the four engineering ledgers. This is idempotent and does not
-- grant the new menu to roles outside the existing platform report scope.
SET @group_dashboard_id := (SELECT id FROM eb_system_menus
    WHERE unique_auth='admin-report-group-management-dashboard' AND is_del=0
    ORDER BY id LIMIT 1);
SET @engineering_parent_id := (SELECT id FROM eb_system_menus
    WHERE unique_auth='admin-data-engineering-management' AND is_del=0
    ORDER BY id LIMIT 1);
SET @store_building_id := (SELECT id FROM eb_system_menus
    WHERE unique_auth='admin-data-engineering-management-store-building' AND is_del=0
    ORDER BY id LIMIT 1);
SET @engineering_quality_id := (SELECT id FROM eb_system_menus
    WHERE unique_auth='admin-data-engineering-management-engineering-quality' AND is_del=0
    ORDER BY id LIMIT 1);
SET @engineering_repair_id := (SELECT id FROM eb_system_menus
    WHERE unique_auth='admin-data-engineering-management-engineering-repair' AND is_del=0
    ORDER BY id LIMIT 1);
SET @rent_renewal_id := (SELECT id FROM eb_system_menus
    WHERE unique_auth='admin-data-engineering-management-rent-renewal' AND is_del=0
    ORDER BY id LIMIT 1);

UPDATE eb_system_role
SET rules = CONCAT_WS(',',
    NULLIF(TRIM(BOTH ',' FROM COALESCE(rules,'')),''),
    IF(@engineering_parent_id IS NULL OR FIND_IN_SET(@engineering_parent_id, rules), NULL, @engineering_parent_id),
    IF(@store_building_id IS NULL OR FIND_IN_SET(@store_building_id, rules), NULL, @store_building_id),
    IF(@engineering_quality_id IS NULL OR FIND_IN_SET(@engineering_quality_id, rules), NULL, @engineering_quality_id),
    IF(@engineering_repair_id IS NULL OR FIND_IN_SET(@engineering_repair_id, rules), NULL, @engineering_repair_id),
    IF(@rent_renewal_id IS NULL OR FIND_IN_SET(@rent_renewal_id, rules), NULL, @rent_renewal_id)
)
WHERE status=1
  AND @group_dashboard_id IS NOT NULL
  AND FIND_IN_SET(@group_dashboard_id, rules)>0
  AND @engineering_parent_id IS NOT NULL;

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260821-002-platform-engineering-ledger-role-inheritance',
       '第九阶段平台工程管理权限继承修复',
       '2026-08-21-第九阶段业务台账/05-平台工程管理权限继承修复.sql',
       '', '', NOW(), 'codex-local',
       'inherited engineering ledger menu access for active roles already authorized for group management dashboard'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log
                  WHERE upgrade_key='20260821-002-platform-engineering-ledger-role-inheritance');

COMMIT;
