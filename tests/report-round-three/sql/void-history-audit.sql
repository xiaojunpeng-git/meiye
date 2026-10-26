-- Read-only, MySQL 5.6 compatible. Run against one authorized instance and
-- one bounded operation-date window at a time. Empty result sets are expected.
-- Do not repair from these rows without comparing the source order, ledger,
-- original facts and operation event; facts are immutable audit evidence.
SET @audit_tenant := '0';
SET @audit_start := '2026-01-01';
SET @audit_end := '2026-12-31';

-- 1. Succeeded sales/recharge voids whose original receipt lacks a reversal.
SELECT 'lifecycle_payment_reversal_missing' AS issue,
       o.operation_id, o.source_type, o.source_order_id, p.fact_id AS original_fact_id,
       p.amount_cents, o.business_date AS void_date
FROM eb_cashier_v3_order_lifecycle_operation o
JOIN eb_cashier_v3_payment_fact p
  ON p.tenant_id = o.tenant_id
 AND p.order_id = CASE WHEN o.source_type = 'recharge'
                       THEN CONCAT('RCH:', o.source_order_id)
                       ELSE o.source_order_id END
 AND p.fact_direction = 'forward' AND p.status = 'effective'
WHERE o.tenant_id = @audit_tenant AND o.status = 'succeeded'
  AND o.operation_type = 'void' AND o.source_type IN ('sales', 'recharge')
  AND o.business_date BETWEEN @audit_start AND @audit_end
  AND NOT EXISTS (
    SELECT 1 FROM eb_cashier_v3_payment_fact r
    WHERE r.tenant_id = p.tenant_id AND r.reversal_of = p.fact_id
      AND r.fact_direction = 'reversal' AND r.status = 'effective'
  )
ORDER BY o.id, p.id LIMIT 500;

-- 6. A voided first course may have blocked later first-course lifecycle events.
-- These members need a separate event/projection rebuild review; do not infer
-- settlement from the sale fact alone or overwrite the immutable history.
SELECT 'voided_first_course_followed_by_card_sale' AS issue,
       life.member_id, life.first_course_order_id AS voided_order_id,
       MIN(s.business_date) AS later_card_business_date,
       COUNT(DISTINCT s.order_id) AS later_card_orders
FROM eb_cashier_v3_customer_lifecycle_projection life
JOIN eb_cashier_v3_order_lifecycle_operation o
  ON o.tenant_id = life.tenant_id AND o.source_order_id = life.first_course_order_id
 AND o.source_type = 'sales' AND o.operation_type = 'void' AND o.status = 'succeeded'
JOIN eb_cashier_v3_sale_fact s
  ON s.tenant_id = life.tenant_id AND s.member_id = life.member_id
 AND s.order_id <> life.first_course_order_id
 AND s.source_type = 'card' AND s.fact_direction = 'forward' AND s.status = 'effective'
 AND s.business_date >= life.first_course_business_date
LEFT JOIN eb_cashier_v3_order_lifecycle_operation later_void
  ON later_void.tenant_id = s.tenant_id AND later_void.source_order_id = s.order_id
 AND later_void.source_type = 'sales' AND later_void.operation_type = 'void'
 AND later_void.status = 'succeeded'
WHERE life.tenant_id = @audit_tenant
  AND o.business_date BETWEEN @audit_start AND @audit_end
  AND later_void.id IS NULL
GROUP BY life.member_id, life.first_course_order_id
ORDER BY life.member_id LIMIT 500;

-- 1b. The same lifecycle void must also reverse its original performance.
SELECT 'lifecycle_performance_reversal_missing' AS issue,
       o.operation_id, o.source_type, o.source_order_id, p.fact_id AS original_fact_id,
       p.performance_type, p.amount_cents, o.business_date AS void_date
FROM eb_cashier_v3_order_lifecycle_operation o
JOIN eb_cashier_v3_performance_fact p
  ON p.tenant_id = o.tenant_id
 AND p.order_id = CASE WHEN o.source_type = 'recharge'
                       THEN CONCAT('RCH:', o.source_order_id)
                       ELSE o.source_order_id END
 AND p.fact_direction = 'forward' AND p.status = 'effective'
WHERE o.tenant_id = @audit_tenant AND o.status = 'succeeded'
  AND o.operation_type = 'void' AND o.source_type IN ('sales', 'recharge')
  AND o.business_date BETWEEN @audit_start AND @audit_end
  AND NOT EXISTS (
    SELECT 1 FROM eb_cashier_v3_performance_fact r
    WHERE r.tenant_id = p.tenant_id AND r.reversal_of = p.fact_id
      AND r.fact_direction = 'reversal' AND r.status = 'effective'
  )
ORDER BY o.id, p.id LIMIT 500;

-- 2. Succeeded supplement voids whose receipt lacks a typed reversal.
SELECT 'supplement_payment_reversal_missing' AS issue,
       o.operation_id, o.source_kind, o.source_id, p.fact_id AS original_fact_id,
       p.amount_cents, DATE(FROM_UNIXTIME(o.created_at)) AS void_date
FROM eb_cashier_v3_order_center_void_operation o
JOIN eb_cashier_v3_payment_fact p
  ON p.tenant_id = o.tenant_id AND p.store_id = o.store_id
 AND p.order_id = o.source_id
 AND p.source_document_type = CASE WHEN o.source_kind = 'sales_supplement'
                                   THEN 'debt_repayment' ELSE 'recharge_debt_repayment' END
 AND p.fact_direction = 'forward' AND p.status = 'effective'
WHERE o.tenant_id = @audit_tenant AND o.status = 'succeeded'
  AND o.source_kind IN ('sales_supplement', 'recharge_supplement')
  AND o.created_at BETWEEN UNIX_TIMESTAMP(@audit_start)
                       AND UNIX_TIMESTAMP(DATE_ADD(@audit_end, INTERVAL 1 DAY)) - 1
  AND NOT EXISTS (
    SELECT 1 FROM eb_cashier_v3_payment_fact r
    WHERE r.tenant_id = p.tenant_id AND r.reversal_of = p.fact_id
      AND r.fact_direction = 'reversal' AND r.status = 'effective'
  )
ORDER BY o.id, p.id LIMIT 500;

-- 2b. Repayment performance must be reversed with the same document type.
SELECT 'supplement_performance_reversal_missing' AS issue,
       o.operation_id, o.source_kind, o.source_id, p.fact_id AS original_fact_id,
       p.performance_type, p.amount_cents, DATE(FROM_UNIXTIME(o.created_at)) AS void_date
FROM eb_cashier_v3_order_center_void_operation o
JOIN eb_cashier_v3_performance_fact p
  ON p.tenant_id = o.tenant_id AND p.store_id = o.store_id
 AND p.order_id = o.source_id
 AND p.source_document_type = CASE WHEN o.source_kind = 'sales_supplement'
                                   THEN 'debt_repayment' ELSE 'recharge_debt_repayment' END
 AND p.fact_direction = 'forward' AND p.status = 'effective'
WHERE o.tenant_id = @audit_tenant AND o.status = 'succeeded'
  AND o.source_kind IN ('sales_supplement', 'recharge_supplement')
  AND o.created_at BETWEEN UNIX_TIMESTAMP(@audit_start)
                       AND UNIX_TIMESTAMP(DATE_ADD(@audit_end, INTERVAL 1 DAY)) - 1
  AND NOT EXISTS (
    SELECT 1 FROM eb_cashier_v3_performance_fact r
    WHERE r.tenant_id = p.tenant_id AND r.reversal_of = p.fact_id
      AND r.fact_direction = 'reversal' AND r.status = 'effective'
  )
ORDER BY o.id, p.id LIMIT 500;

-- 3. Sales repayment allocations lacking their immutable negative partner.
SELECT 'supplement_allocation_reversal_missing' AS issue,
       o.operation_id, o.source_id, a.allocation_fact_id AS original_fact_id,
       a.amount_cents, DATE(FROM_UNIXTIME(o.created_at)) AS void_date
FROM eb_cashier_v3_order_center_void_operation o
JOIN eb_cashier_v3_payment_fact p
  ON p.tenant_id = o.tenant_id AND p.store_id = o.store_id
 AND p.order_id = o.source_id AND p.source_document_type = 'debt_repayment'
 AND p.fact_direction = 'forward' AND p.status = 'effective'
JOIN eb_cashier_v3_payment_sale_allocation_fact a
  ON a.tenant_id = p.tenant_id AND a.payment_fact_id = p.fact_id
 AND a.fact_direction = 'forward' AND a.status = 'effective'
WHERE o.tenant_id = @audit_tenant AND o.source_kind = 'sales_supplement'
  AND o.status = 'succeeded'
  AND o.created_at BETWEEN UNIX_TIMESTAMP(@audit_start)
                       AND UNIX_TIMESTAMP(DATE_ADD(@audit_end, INTERVAL 1 DAY)) - 1
  AND NOT EXISTS (
    SELECT 1 FROM eb_cashier_v3_payment_sale_allocation_fact r
    WHERE r.tenant_id = a.tenant_id AND r.reversal_of = a.allocation_fact_id
      AND r.fact_direction = 'reversal' AND r.status = 'effective'
  )
ORDER BY o.id, a.id LIMIT 500;

-- 4. Recharge repayment credits lacking the debited balance fact.
SELECT 'supplement_balance_reversal_missing' AS issue,
       o.operation_id, o.source_id, b.fact_id AS original_fact_id,
       b.principal_delta_cents, DATE(FROM_UNIXTIME(o.created_at)) AS void_date
FROM eb_cashier_v3_order_center_void_operation o
JOIN eb_cashier_v3_balance_fact b
  ON b.tenant_id = o.tenant_id AND b.store_id = o.store_id
 AND b.order_id = o.source_id
 AND b.source_document_type = 'recharge_debt_repayment'
 AND b.fact_direction = 'forward' AND b.status = 'effective'
WHERE o.tenant_id = @audit_tenant AND o.source_kind = 'recharge_supplement'
  AND o.status = 'succeeded'
  AND o.created_at BETWEEN UNIX_TIMESTAMP(@audit_start)
                       AND UNIX_TIMESTAMP(DATE_ADD(@audit_end, INTERVAL 1 DAY)) - 1
  AND NOT EXISTS (
    SELECT 1 FROM eb_cashier_v3_balance_fact r
    WHERE r.tenant_id = b.tenant_id AND r.reversal_of = b.fact_id
      AND r.fact_direction = 'reversal' AND r.status = 'effective'
  )
ORDER BY o.id, b.id LIMIT 500;

-- 5. Succeeded service voids lacking consumption/labor fact reversals.
SELECT 'service_performance_reversal_missing' AS issue,
       o.operation_id, o.service_fact_id, p.fact_id AS original_fact_id,
       p.performance_type, p.amount_cents, o.business_date AS void_date
FROM eb_cashier_v3_service_record_void_operation o
JOIN eb_cashier_v3_entitlement_service_fact s
  ON s.tenant_id = o.tenant_id AND s.id = o.service_fact_id
JOIN eb_cashier_v3_performance_fact p
  ON p.tenant_id = s.tenant_id AND p.store_id = s.store_id
 AND p.checkout_request_id = s.checkout_request_id
 AND p.source_line_id = s.source_line_id
 AND p.performance_type IN ('consumption_performance_recorded', 'labor_performance_allocated')
 AND p.fact_direction = 'forward' AND p.status = 'effective'
WHERE o.tenant_id = @audit_tenant AND o.status = 'succeeded'
  AND o.business_date BETWEEN @audit_start AND @audit_end
  AND NOT EXISTS (
    SELECT 1 FROM eb_cashier_v3_performance_fact r
    WHERE r.tenant_id = p.tenant_id AND r.reversal_of = p.fact_id
      AND r.fact_direction = 'reversal' AND r.status = 'effective'
  )
ORDER BY o.id, p.id LIMIT 500;
