SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_business_ledger_store_building','eb_business_ledger_engineering_quality','eb_business_ledger_engineering_repair','eb_business_ledger_rent_renewal','eb_business_ledger_audit','eb_business_ledger_rent_reminder');
SELECT COUNT(*) AS stores FROM eb_system_store WHERE is_del = 0;
SELECT COUNT(*) AS data_menu_count
FROM eb_system_menus
WHERE unique_auth = 'admin-report' AND type = 1 AND is_del = 0;
SELECT unique_auth, pid, menu_name, path
FROM eb_system_menus
WHERE unique_auth = 'admin-data-engineering-management' AND is_del = 0;
