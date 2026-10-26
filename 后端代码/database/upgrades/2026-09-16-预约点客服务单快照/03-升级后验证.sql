-- upgrade_key: 20260916-001-cashier-v3-reservation-point-customer-service-snapshot-v1
SET NAMES utf8mb4;
SET @point_customer_service_db := DATABASE();

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@point_customer_service_db
  AND TABLE_NAME='eb_cashier_v3_service_order_staff_assignment';

SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@point_customer_service_db
  AND TABLE_NAME='eb_cashier_v3_service_order_staff_assignment'
  AND COLUMN_NAME='is_point_customer';

SELECT INDEX_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@point_customer_service_db
  AND TABLE_NAME='eb_cashier_v3_service_order_staff_assignment'
  AND INDEX_NAME IN ('uk_tenant_service_order_staff','idx_tenant_staff_service_order','idx_tenant_service_order')
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT COUNT(*) AS upgrade_log_count
FROM eb_database_upgrade_log
WHERE upgrade_key='20260916-001-cashier-v3-reservation-point-customer-service-snapshot-v1';

SELECT 'POSTCHECK_OK' AS postcheck_result;
