-- Both mismatch counts must be zero. Run separately for each authorized instance.
SELECT COUNT(*) AS group_fact_rows,
       SUM(o.id IS NULL) AS missing_operation,
       SUM(p.id IS NULL) AS missing_labor_fact,
       SUM(o.id IS NOT NULL AND g.source_fingerprint <> o.immutable_fingerprint) AS operation_fingerprint_mismatch
FROM eb_cashier_v3_service_customer_group_fact g
LEFT JOIN eb_cashier_v3_service_record_adjustment_operation o
  ON o.tenant_id=g.tenant_id AND o.operation_id=g.operation_id
 AND o.service_fact_id=g.service_fact_id AND o.business_event_no=g.business_event_no
LEFT JOIN eb_cashier_v3_performance_fact p
  ON p.tenant_id=g.tenant_id AND p.fact_id=g.performance_fact_id
 AND p.employee_id=g.employee_id AND p.business_event_no=g.business_event_no;

SELECT capture_mode, allocation_group_key, COUNT(*) AS row_count
FROM eb_cashier_v3_service_customer_group_fact
GROUP BY capture_mode, allocation_group_key
ORDER BY capture_mode, allocation_group_key;
