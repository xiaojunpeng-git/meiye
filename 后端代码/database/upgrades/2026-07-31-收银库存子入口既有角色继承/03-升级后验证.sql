-- upgrade_key: 20260731-003-cashier-v3-inventory-existing-role-grants
SET NAMES utf8mb4;

SELECT COUNT(*) AS active_cashier_roles_missing_inventory_entries
FROM eb_system_role r
WHERE r.status = 1
  AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE type = 3 AND unique_auth = 'cashier-cashier-index' AND is_del = 0 LIMIT 1), r.cashier_rules) > 0
  AND (
    SELECT COUNT(*)
    FROM eb_system_menus m
    WHERE m.type = 3
      AND m.unique_auth LIKE 'cashier-inventory-%'
      AND m.is_del = 0
      AND FIND_IN_SET(m.id, r.cashier_rules) > 0
  ) <> 11;

SELECT IF(
  (SELECT COUNT(*) FROM eb_system_role r
   WHERE r.status = 1
     AND FIND_IN_SET((SELECT id FROM eb_system_menus WHERE type = 3 AND unique_auth = 'cashier-cashier-index' AND is_del = 0 LIMIT 1), r.cashier_rules) > 0
     AND (SELECT COUNT(*) FROM eb_system_menus m WHERE m.type = 3 AND m.unique_auth LIKE 'cashier-inventory-%' AND m.is_del = 0 AND FIND_IN_SET(m.id, r.cashier_rules) > 0) <> 11
  ) = 0,
  'POSTCHECK_OK',
  'POSTCHECK_BLOCKED'
) AS postcheck_result;
