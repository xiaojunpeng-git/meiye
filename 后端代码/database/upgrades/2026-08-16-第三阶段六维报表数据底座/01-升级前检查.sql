-- upgrade_key: 20260816-002-phase-three-six-dimension-report-foundation
-- 仅检查，不修改数据。正式升级前必须逐实例核对并备份。
SET NAMES utf8mb4;

SELECT VERSION() AS mysql_version, DATABASE() AS database_name;

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_organization', 'eb_system_store', 'eb_user', 'eb_user_belong_store',
    'eb_system_menus', 'eb_database_upgrade_log',
    'eb_cashier_v3_payment_sale_allocation_fact',
    'eb_cashier_v3_card_sale_category_allocation_fact',
    'eb_cashier_v3_card_operation',
    'eb_cashier_v3_report_annotation',
    'eb_cashier_v3_report_annotation_audit'
  )
ORDER BY TABLE_NAME;

SELECT 11-COUNT(*) AS missing_prerequisite_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN (
    'eb_organization', 'eb_system_store', 'eb_user', 'eb_user_belong_store',
    'eb_system_menus', 'eb_database_upgrade_log',
    'eb_cashier_v3_payment_sale_allocation_fact',
    'eb_cashier_v3_card_sale_category_allocation_fact',
    'eb_cashier_v3_card_operation',
    'eb_cashier_v3_report_annotation',
    'eb_cashier_v3_report_annotation_audit'
  );

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_report_organization_dimension',
    'eb_cashier_v3_report_consumption_tier',
    'eb_cashier_v3_report_consumption_tier_audit',
    'eb_cashier_v3_report_member_origin_evidence',
    'eb_cashier_v3_report_member_origin_evidence_audit',
    'eb_cashier_v3_report_member_store_assignment_period',
    'eb_cashier_v3_card_sale_item_allocation_fact'
  )
ORDER BY TABLE_NAME;

SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_report_organization_dimension',
    'eb_cashier_v3_report_consumption_tier'
  )
  AND COLUMN_NAME='deleted_at'
ORDER BY TABLE_NAME,COLUMN_NAME;

SELECT TABLE_NAME,INDEX_NAME,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND (TABLE_NAME='eb_cashier_v3_report_consumption_tier' AND INDEX_NAME='idx_tenant_enabled_order'
       OR TABLE_NAME='eb_cashier_v3_payment_sale_allocation_fact' AND INDEX_NAME='idx_scope_date_status'
       OR TABLE_NAME='eb_cashier_v3_card_operation' AND INDEX_NAME='idx_scope_business_date_status')
GROUP BY TABLE_NAME,INDEX_NAME
ORDER BY TABLE_NAME,INDEX_NAME;

SELECT id, pid, menu_name, menu_path, unique_auth, is_show, is_del
FROM eb_system_menus
WHERE unique_auth IN ('admin-report', 'admin-setting-shop')
   OR unique_auth = 'admin-report-six-dimension'
   OR unique_auth LIKE 'admin-report-six-dimension-%'
   OR unique_auth = 'setting-shop-six-dimension-consumption-tier'
ORDER BY id;

SELECT COUNT(*) AS member_count,
       SUM(add_time < 1786291200) AS pre_cutover_count,
       SUM(user_type = 'import' OR login_type = 'import') AS imported_count
FROM eb_user;

SELECT COUNT(*) AS belong_history_count,
       COUNT(DISTINCT uid) AS belong_history_member_count,
       is_store
FROM eb_user_belong_store
GROUP BY is_store;

SELECT COUNT(*) AS existing_card_category_allocation_count
FROM eb_cashier_v3_card_sale_category_allocation_fact;
