-- upgrade_key: 20260815-007-cashier-v3-service-category-path-report-snapshot
-- MySQL 5.6 compatible, repeatable and additive only.
SET NAMES utf8mb4;
SET @service_category_snapshot_db := DATABASE();

SET @service_category_snapshot_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA=@service_category_snapshot_db
     AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact')=1,
  'SELECT ''SERVICE_CATEGORY_SNAPSHOT_PREREQUISITES_OK'' AS apply_result',
  'SELECT * FROM STOP_SERVICE_CATEGORY_SNAPSHOT_PREREQUISITE_MISSING'
);
PREPARE service_category_snapshot_stmt FROM @service_category_snapshot_sql;
EXECUTE service_category_snapshot_stmt;
DEALLOCATE PREPARE service_category_snapshot_stmt;

SET @service_category_snapshot_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=@service_category_snapshot_db
     AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact'
     AND COLUMN_NAME='project_category_path_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `project_category_path_snapshot` varchar(512) NOT NULL DEFAULT '''' AFTER `project_category_name_snapshot`',
  'SELECT ''project_category_path_snapshot already exists'' AS apply_result'
);
PREPARE service_category_snapshot_stmt FROM @service_category_snapshot_sql;
EXECUTE service_category_snapshot_stmt;
DEALLOCATE PREPARE service_category_snapshot_stmt;

SELECT 'APPLY_OK' AS apply_result;
