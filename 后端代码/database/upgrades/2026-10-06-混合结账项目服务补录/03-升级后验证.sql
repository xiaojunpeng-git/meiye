-- 瑞昊实例只读核对：候选归零、审计与服务事实一一对应。
SELECT COUNT(*) AS remaining_missing_lines
FROM eb_cashier_v3_sales_order o
JOIN eb_cashier_v3_checkout_request r ON r.tenant_id=o.tenant_id AND r.request_id=o.checkout_request_id
JOIN eb_cashier_v3_sales_order_line l ON l.tenant_id=o.tenant_id AND l.order_id=o.order_id
LEFT JOIN eb_cashier_v3_entitlement_service_fact sf ON sf.tenant_id=l.tenant_id
  AND sf.checkout_request_id=l.checkout_request_id AND sf.source_line_id=l.order_line_id
WHERE o.composition='mixed' AND o.order_status='settled' AND o.order_direction='forward'
  AND r.request_status='succeeded' AND l.item_type='project' AND l.line_status='settled'
  AND l.line_direction='forward' AND sf.id IS NULL
  AND NOT EXISTS (SELECT 1 FROM eb_cashier_v3_sales_order_line rev
    WHERE rev.tenant_id=l.tenant_id AND rev.reversal_of_line_id=l.order_line_id);

SELECT COUNT(*) AS audited_lines, COUNT(DISTINCT order_id) AS audited_orders,
  COUNT(DISTINCT service_record_no) AS distinct_service_numbers
FROM eb_cashier_v3_mixed_sale_service_repair_audit;

SELECT COUNT(*) AS broken_audit_links
FROM eb_cashier_v3_mixed_sale_service_repair_audit a
LEFT JOIN eb_cashier_v3_sales_order o ON o.tenant_id=a.tenant_id AND o.order_id=a.order_id
LEFT JOIN eb_cashier_v3_sales_order_line l ON l.tenant_id=a.tenant_id AND l.order_line_id=a.source_line_id
LEFT JOIN eb_cashier_v3_entitlement_service_fact sf ON sf.tenant_id=a.tenant_id
  AND sf.service_fact_id=a.service_fact_id
WHERE o.id IS NULL OR l.id IS NULL OR sf.id IS NULL
  OR sf.source_line_id<>l.order_line_id OR sf.checkout_request_id<>o.checkout_request_id
  OR sf.immutable_fingerprint<>a.service_fingerprint
  OR sf.service_record_no<>a.service_record_no;
