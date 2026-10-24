-- 只读。异常数必须为 0；新表必须不存在。先暂停全部目标写入口，并建立独立恢复点。
SELECT COUNT(*) AS new_table_must_be_zero FROM information_schema.tables
 WHERE table_schema=DATABASE() AND table_name='eb_target_report_line_binding';
SELECT COUNT(*) AS invalid_effective_lines_must_be_zero
 FROM eb_target_effective_line e LEFT JOIN eb_target_scheme_line l ON l.id=e.line_id AND l.tenant_id=e.tenant_id
 WHERE l.id IS NULL OR l.scheme_id<>e.scheme_id OR l.store_id<>e.store_id OR l.month_key<>e.month_key
 OR l.metric_code<>e.metric_code OR l.scope_type<>e.scope_type OR l.scope_id<>e.scope_id;
SELECT COUNT(*) AS invalid_current_bindings_must_be_zero
 FROM eb_target_report_binding b LEFT JOIN eb_target_scheme s ON s.id=b.scheme_id AND s.tenant_id=b.tenant_id
 WHERE s.id IS NULL OR s.status<>'active' OR NOT EXISTS
 (SELECT 1 FROM eb_target_effective_line e WHERE e.tenant_id=b.tenant_id AND e.scheme_id=b.scheme_id AND e.store_id=b.store_id);
SELECT COUNT(*) AS expected_binding_rows
 FROM eb_target_report_binding b JOIN eb_target_effective_line e
 ON e.tenant_id=b.tenant_id AND e.scheme_id=b.scheme_id AND e.store_id=b.store_id;
