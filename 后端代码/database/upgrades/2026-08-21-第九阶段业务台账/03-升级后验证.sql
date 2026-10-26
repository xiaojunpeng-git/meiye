SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('eb_business_ledger_store_building','eb_business_ledger_engineering_quality','eb_business_ledger_engineering_repair','eb_business_ledger_rent_renewal','eb_business_ledger_audit','eb_business_ledger_rent_reminder');
SELECT COUNT(*) AS audit_rows FROM eb_business_ledger_audit;
SELECT COUNT(*) AS rent_reminder_rows FROM eb_business_ledger_rent_reminder;
SELECT child.menu_name, parent.menu_name AS parent_menu_name, child.path
FROM eb_system_menus child
JOIN eb_system_menus parent ON parent.id = child.pid
WHERE child.unique_auth = 'admin-data-engineering-management'
  AND parent.unique_auth = 'admin-report'
  AND child.is_del = 0;
