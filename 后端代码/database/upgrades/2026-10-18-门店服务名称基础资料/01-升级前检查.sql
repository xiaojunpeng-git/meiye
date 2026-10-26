-- upgrade_key: 20261018-001-store-service-name-master
-- 只读检查；正式执行前必须确认当前连接就是目标客户的独立数据库。
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_service_name', 'eb_cashier_v3_service_name_setting', 'eb_cashier_v3_service_name_audit')
ORDER BY TABLE_NAME;

SELECT upgrade_key, executed_at, executed_by, result_note
FROM eb_database_upgrade_log
WHERE upgrade_key = '20261018-001-store-service-name-master';

SELECT
  (SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_service_name') AS service_name_table_count,
  (SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_service_name_setting') AS service_name_setting_table_count,
  (SELECT COUNT(*) FROM information_schema.TABLES
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_service_name_audit') AS audit_table_count,
  (SELECT COUNT(*) FROM eb_database_upgrade_log
   WHERE upgrade_key = '20261018-001-store-service-name-master') AS upgrade_log_count;
