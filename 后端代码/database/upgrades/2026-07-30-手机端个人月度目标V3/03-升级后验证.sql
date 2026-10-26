-- upgrade_key: 20260730-004-mobile-personal-monthly-target-v1
SELECT COUNT(*) AS target_table_count
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'eb_mobile_personal_monthly_target',
    'eb_mobile_personal_monthly_target_line',
    'eb_mobile_personal_monthly_target_command',
    'eb_mobile_personal_monthly_target_audit'
  );
SELECT COUNT(*) AS target_unique_indexes
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('eb_mobile_personal_monthly_target','eb_mobile_personal_monthly_target_line','eb_mobile_personal_monthly_target_command')
  AND non_unique = 0;
SELECT COUNT(*) AS upgrade_logged
FROM eb_database_upgrade_log
WHERE upgrade_key = '20260730-004-mobile-personal-monthly-target-v1';
