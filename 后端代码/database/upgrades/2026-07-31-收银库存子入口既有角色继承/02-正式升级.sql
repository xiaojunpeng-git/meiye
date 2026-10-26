-- upgrade_key: 20260731-003-cashier-v3-inventory-existing-role-grants
-- The new permissions are capability records; this preserves pre-split access
-- for every active role which already had the original cashier entry.
SET NAMES utf8mb4;

SET @cashier_entry_id := (
  SELECT id FROM eb_system_menus
  WHERE type = 3 AND unique_auth = 'cashier-cashier-index' AND is_del = 0
  LIMIT 1
);
SET @inventory_overview_id := (
  SELECT id FROM eb_system_menus
  WHERE type = 3 AND unique_auth = 'cashier-inventory-overview' AND is_del = 0
  LIMIT 1
);
SET @inventory_entry_ids := (
  SELECT GROUP_CONCAT(id ORDER BY id SEPARATOR ',')
  FROM eb_system_menus
  WHERE type = 3 AND unique_auth LIKE 'cashier-inventory-%' AND is_del = 0
);

UPDATE eb_system_role
SET cashier_rules = CONCAT(
  TRIM(BOTH ',' FROM COALESCE(cashier_rules, '')),
  IF(TRIM(BOTH ',' FROM COALESCE(cashier_rules, '')) = '', '', ','),
  @inventory_entry_ids
)
WHERE status = 1
  AND @cashier_entry_id IS NOT NULL
  AND @inventory_overview_id IS NOT NULL
  AND @inventory_entry_ids IS NOT NULL
  AND FIND_IN_SET(@cashier_entry_id, cashier_rules) > 0
  AND FIND_IN_SET(@inventory_overview_id, cashier_rules) = 0;

SELECT ROW_COUNT() AS inherited_role_count, 'APPLY_OK' AS apply_result;
