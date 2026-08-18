-- upgrade_key: 20260817-003-cashier-v3-source-settings-edit-status-only
-- MySQL 5.6 compatible. It changes endpoint permissions only, never source data.
SET NAMES utf8mb4;

UPDATE eb_system_menus
SET menu_name='读取结账来源设置',
    is_del=0
WHERE type=1
  AND api_url='product/business-config/sources'
  AND methods='GET';

UPDATE eb_system_menus
SET menu_name='修改结账来源',
    is_del=0
WHERE type=1
  AND api_url='product/business-config/sources/:id'
  AND methods='PUT';

UPDATE eb_system_menus
SET menu_name='新增结账来源（已停用）',
    is_del=1
WHERE type=1
  AND api_url='product/business-config/sources'
  AND methods='POST';

INSERT INTO eb_database_upgrade_log (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260817-003-cashier-v3-source-settings-edit-status-only',
  '结账来源仅编辑启停',
  '2026-08-17-结账来源仅编辑启停/02-正式升级.sql',
  '', '', NOW(), 'codex', '已停用结账来源新增接口权限，保留现有来源读取和修改权限'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20260817-003-cashier-v3-source-settings-edit-status-only'
);

SELECT 'APPLY_OK' AS apply_result;
