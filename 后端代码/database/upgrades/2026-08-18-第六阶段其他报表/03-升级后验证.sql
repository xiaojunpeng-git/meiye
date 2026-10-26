SELECT unique_auth, menu_name, pid, path
FROM eb_system_menus
WHERE is_del=0 AND (unique_auth='admin-report-other-reports' OR unique_auth LIKE 'admin-report-phase-six-%')
ORDER BY pid, sort;
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND ((TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='project_count')
    OR (TABLE_NAME='eb_employee' AND COLUMN_NAME='mentor_employee_id')
    OR (TABLE_NAME='eb_system_store_staff' AND COLUMN_NAME='mentor_employee_id'));
SELECT COUNT(*) AS establishment_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_report_beautician_establishment';
