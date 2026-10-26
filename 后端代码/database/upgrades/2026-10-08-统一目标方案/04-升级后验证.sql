-- 只读核验结构和菜单；门店权限、报表完成值与冲突保护还须真人操作验证。
SELECT table_name
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('eb_target_scheme','eb_target_scheme_line','eb_target_effective_line',
    'eb_target_report_binding','eb_target_scheme_command','eb_target_scheme_audit')
ORDER BY table_name;
SELECT id,pid,menu_name,unique_auth,is_show,menu_path
FROM eb_system_menus
WHERE unique_auth IN ('admin-target','admin-target-scheme','admin-target-data-analysis',
  'admin-target-monthly-detail','admin-target-store-detail')
ORDER BY id;
SELECT tenant_id,store_id,month_key,metric_code,scope_type,scope_id,COUNT(*) AS effective_count
FROM eb_target_effective_line
GROUP BY tenant_id,store_id,month_key,metric_code,scope_type,scope_id
HAVING COUNT(*) > 1;
SELECT tenant_id,report_code,store_id,COUNT(*) AS binding_count
FROM eb_target_report_binding
GROUP BY tenant_id,report_code,store_id
HAVING COUNT(*) > 1;
