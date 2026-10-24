-- 仅用于切换前、尚未恢复写入时核对迁移。所有异常数必须为 0。
SELECT COUNT(*) AS missing_bindings_must_be_zero
 FROM eb_target_report_binding b JOIN eb_target_effective_line e
 ON e.tenant_id=b.tenant_id AND e.scheme_id=b.scheme_id AND e.store_id=b.store_id
 LEFT JOIN eb_target_report_line_binding n
 ON n.tenant_id=b.tenant_id AND n.report_code=b.report_code AND n.line_id=e.line_id AND n.scheme_id=e.scheme_id
 WHERE n.id IS NULL;
SELECT COUNT(*) AS extra_bindings_must_be_zero
 FROM eb_target_report_line_binding n
 LEFT JOIN eb_target_report_binding b ON b.tenant_id=n.tenant_id AND b.report_code=n.report_code AND b.store_id=n.store_id AND b.scheme_id=n.scheme_id
 LEFT JOIN eb_target_effective_line e ON e.tenant_id=n.tenant_id AND e.line_id=n.line_id AND e.scheme_id=n.scheme_id
 WHERE b.id IS NULL OR e.id IS NULL;
SELECT COUNT(*) AS invalid_identity_must_be_zero
 FROM eb_target_report_line_binding n LEFT JOIN eb_target_scheme_line l ON l.id=n.line_id AND l.tenant_id=n.tenant_id
 WHERE l.id IS NULL OR n.scheme_id<>l.scheme_id OR n.store_id<>l.store_id OR n.month_key<>l.month_key
 OR n.metric_code<>l.metric_code OR n.scope_type<>l.scope_type OR n.scope_id<>l.scope_id;
