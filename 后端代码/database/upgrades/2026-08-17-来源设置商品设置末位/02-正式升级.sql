-- upgrade_key: 20260817-005-business-source-menu-last
-- MySQL 5.6 compatible. Only the visible source-settings menu ordering changes.
SET NAMES utf8mb4;

SET @shop_parent_id := COALESCE((
  SELECT id FROM eb_system_menus
  WHERE type=1 AND is_del=0 AND unique_auth='admin-setting-shop'
  ORDER BY id ASC LIMIT 1
),0);

SET @source_sort := COALESCE((
  SELECT MAX(sort)
  FROM eb_system_menus
  WHERE type=1
    AND is_del=0
    AND is_show=1
    AND pid=@shop_parent_id
    AND unique_auth<>'setting-shop-business-source'
),0) + 1;

UPDATE eb_system_menus
SET sort=@source_sort
WHERE type=1
  AND is_del=0
  AND unique_auth='setting-shop-business-source'
  AND pid=@shop_parent_id;

INSERT INTO eb_database_upgrade_log (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20260817-005-business-source-menu-last',
  '来源设置置于商品设置末位',
  '2026-08-17-来源设置商品设置末位/02-正式升级.sql',
  '', '', NOW(), 'codex', '已将商品设置下的来源设置排序为最后一个可见功能'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM eb_database_upgrade_log
  WHERE upgrade_key='20260817-005-business-source-menu-last'
);

SELECT 'APPLY_OK' AS apply_result;
