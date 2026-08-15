-- upgrade_key: 20260815-007-cashier-v3-service-category-path-report-snapshot
SET NAMES utf8mb4;

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'eb_cashier_v3_entitlement_service_fact'
  AND COLUMN_NAME = 'project_category_path_snapshot';

SELECT 'VERIFY_OK' AS verify_result;
