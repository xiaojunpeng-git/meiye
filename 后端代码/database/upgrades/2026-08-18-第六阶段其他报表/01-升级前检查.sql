-- upgrade_key: 20260818-001-phase-six-reports
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_system_menus','eb_cashier_v3_entitlement_service_fact','employee','eb_cashier_v3_report_beautician_establishment');
