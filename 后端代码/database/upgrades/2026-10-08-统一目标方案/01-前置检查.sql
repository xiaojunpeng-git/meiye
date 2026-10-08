-- 仅只读检查：确认目标实例、旧表与六张新表当前状态；不要把本地结果套用到生产库。
SELECT DATABASE() AS current_database, VERSION() AS mysql_version;
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('eb_target_scheme','eb_target_scheme_line','eb_target_effective_line',
    'eb_target_report_binding','eb_target_scheme_command','eb_target_scheme_audit')
ORDER BY table_name;
SELECT id,pid,menu_name,unique_auth,is_show,menu_path
FROM eb_system_menus
WHERE unique_auth IN ('admin-report','admin-target','admin-target-scheme',
  'admin-target-data-analysis','admin-target-monthly-detail','admin-target-store-detail')
ORDER BY id;
