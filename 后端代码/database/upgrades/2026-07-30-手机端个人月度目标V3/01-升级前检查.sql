-- upgrade_key: 20260730-004-mobile-personal-monthly-target-v1
-- Read-only precheck. The formal upgrade creates only new mobile target tables.
SELECT DATABASE() AS current_database;
SELECT COUNT(*) AS target_table_conflicts
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'eb_mobile_personal_monthly_target',
    'eb_mobile_personal_monthly_target_line',
    'eb_mobile_personal_monthly_target_command',
    'eb_mobile_personal_monthly_target_audit'
  );
SELECT COUNT(*) AS upgrade_already_logged
FROM eb_database_upgrade_log
WHERE upgrade_key = '20260730-004-mobile-personal-monthly-target-v1';
