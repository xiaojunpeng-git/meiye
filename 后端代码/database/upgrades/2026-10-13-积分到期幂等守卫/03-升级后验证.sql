SELECT DATABASE() AS target_database;
SELECT COUNT(*) AS guard_exists FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user_integral_expiry';
SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
 FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_user_integral_expiry'
 GROUP BY INDEX_NAME, NON_UNIQUE;
SELECT COUNT(*) AS repeated_guard_groups FROM (
  SELECT uid, period_start FROM eb_user_integral_expiry
  GROUP BY uid, period_start HAVING COUNT(*) > 1
) repeated;
SELECT upgrade_key, executed_at FROM eb_database_upgrade_log
 WHERE upgrade_key = '20261013-001-integral-expiry-guard';
