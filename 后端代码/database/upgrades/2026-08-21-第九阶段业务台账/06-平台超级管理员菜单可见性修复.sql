SET NAMES utf8mb4;

-- The backend bypasses menu checks for level-0 platform administrators, but
-- the Vue 2 dynamic router still reads role rules. Keep both sides aligned so
-- a super administrator does not hit the client-side 403 page.
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
WHERE status=1 AND level=0 AND @engineering_parent_id IS NOT NULL;

INSERT INTO eb_database_upgrade_log
(`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260821-003-platform-engineering-ledger-super-admin-visibility',
       '第九阶段平台超级管理员菜单可见性修复',
       '2026-08-21-第九阶段业务台账/06-平台超级管理员菜单可见性修复.sql',
       '', '', NOW(), 'codex-local',
       'aligned level-0 platform role menus with the existing backend permission bypass'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM eb_database_upgrade_log
                  WHERE upgrade_key='20260821-003-platform-engineering-ledger-super-admin-visibility');

COMMIT;
