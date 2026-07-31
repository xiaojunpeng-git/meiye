-- upgrade_key: 20260731-002-cashier-v3-inventory-submenu-permissions
SET NAMES utf8mb4;
SELECT COUNT(*) INTO @civp_nodes FROM eb_system_menus
WHERE type=3 AND auth_type=1 AND is_del=0 AND unique_auth IN (
  'cashier-inventory-overview','cashier-inventory-inbound','cashier-inventory-outbound','cashier-inventory-stock',
  'cashier-inventory-count','cashier-inventory-movement','cashier-inventory-statistics','cashier-inventory-request',
  'cashier-inventory-transfer','cashier-inventory-usage','cashier-inventory-import'
);
SELECT COUNT(*) INTO @civp_duplicates FROM (
  SELECT unique_auth,COUNT(*) AS c FROM eb_system_menus
  WHERE type=3 AND is_del=0 AND unique_auth LIKE 'cashier-inventory-%'
  GROUP BY unique_auth HAVING c>1
) civp_duplicate;
SELECT @civp_nodes AS inventory_permission_node_count,@civp_duplicates AS duplicate_permission_count;
SET @civp_abort := IF(@civp_nodes=11 AND @civp_duplicates=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''cashier inventory submenu permission postcheck failed''');
PREPARE civp_stmt FROM @civp_abort; EXECUTE civp_stmt; DEALLOCATE PREPARE civp_stmt;
