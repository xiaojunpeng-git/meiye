SET NAMES utf8mb4;

SELECT COUNT(*) AS missing_authority_tables
FROM (
  SELECT 'eb_cashier_v3_reservation' AS table_name
  UNION ALL SELECT 'eb_cashier_v3_reservation_line'
  UNION ALL SELECT 'eb_cashier_v3_service_order'
  UNION ALL SELECT 'eb_cashier_v3_service_order_line'
  UNION ALL SELECT 'eb_cashier_v3_service_order_entitlement_guard'
  UNION ALL SELECT 'eb_cashier_v3_business_event'
  UNION ALL SELECT 'eb_cashier_v3_business_document_no'
  UNION ALL SELECT 'eb_cashier_v3_business_document_sequence'
  UNION ALL SELECT 'eb_cashier_v3_entitlement_writeoff_fact'
  UNION ALL SELECT 'eb_cashier_v3_entitlement_service_fact'
  UNION ALL SELECT 'eb_cashier_v3_performance_fact'
  UNION ALL SELECT 'eb_cashier_v3_project_performance_rule'
  UNION ALL SELECT 'eb_cashier_v3_customer_lifecycle_fact'
  UNION ALL SELECT 'eb_cashier_v3_customer_lifecycle_projection'
  UNION ALL SELECT 'eb_database_upgrade_log'
) required_tables
LEFT JOIN information_schema.TABLES t
  ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = required_tables.table_name
WHERE t.TABLE_NAME IS NULL;

SELECT COUNT(*) AS lifecycle_generation_column_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_reservation'
  AND COLUMN_NAME='lifecycle_generation';
