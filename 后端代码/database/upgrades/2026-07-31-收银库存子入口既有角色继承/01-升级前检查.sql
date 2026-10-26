-- upgrade_key: 20260731-003-cashier-v3-inventory-existing-role-grants
-- MySQL 5.6.51 compatible.
SET NAMES utf8mb4;

SELECT COUNT(*) AS cashier_entry_count
FROM eb_system_menus
WHERE type = 3 AND unique_auth = 'cashier-cashier-index' AND is_del = 0;

SELECT COUNT(*) AS inventory_entry_count
FROM eb_system_menus
WHERE type = 3 AND unique_auth LIKE 'cashier-inventory-%' AND is_del = 0;

SELECT COUNT(*) AS active_cashier_role_count
FROM eb_system_role r
WHERE r.status = 1
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE type = 3 AND unique_auth = 'cashier-cashier-index' AND is_del = 0 LIMIT 1), r.cashier_rules) > 0;

SELECT IF(
  (SELECT COUNT(*) FROM eb_system_menus WHERE type = 3 AND unique_auth = 'cashier-cashier-index' AND is_del = 0) = 1
  AND (SELECT COUNT(*) FROM eb_system_menus WHERE type = 3 AND unique_auth LIKE 'cashier-inventory-%' AND is_del = 0) = 11,
  'PRECHECK_OK',
  'PRECHECK_BLOCKED'
) AS precheck_result;
