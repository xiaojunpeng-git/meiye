-- upgrade_key: 20260828-002-customer-source-lifecycle-backfill
-- Scope: only immutable cashier V3 sale facts. Legacy eb_store_order rows are
-- intentionally excluded because their source snapshots are not recoverable.
-- Re-runnable: the lifecycle fact natural key and tenant/member projection
-- guard make this safe to execute more than once.
SET NAMES utf8mb4;

DROP TEMPORARY TABLE IF EXISTS tmp_customer_source_first_course;
CREATE TEMPORARY TABLE tmp_customer_source_first_course AS
SELECT sf.*
FROM eb_cashier_v3_sale_fact sf
JOIN (
    SELECT tenant_id, member_id, MIN(id) AS first_id
    FROM eb_cashier_v3_sale_fact
    WHERE status = 'effective'
      AND fact_direction = 'forward'
      AND source_type = 'card'
      AND member_id > 0
      AND debt_amount_cents = 0
    GROUP BY tenant_id, member_id
) first_row ON first_row.tenant_id = sf.tenant_id
          AND first_row.member_id = sf.member_id
          AND first_row.first_id = sf.id
WHERE sf.status = 'effective'
  AND sf.fact_direction = 'forward'
  AND sf.source_type = 'card'
  AND sf.member_id > 0
  AND sf.debt_amount_cents = 0;

INSERT IGNORE INTO eb_cashier_v3_customer_lifecycle_fact (
    fact_id, natural_key, tenant_id, organization_id, store_id, member_id,
    event_type, lifecycle_stage_after, related_order_id,
    related_order_no_snapshot, source_primary_id,
    source_primary_name_snapshot, source_secondary_id,
    source_secondary_name_snapshot, source_label_snapshot,
    source_attribution_type_snapshot, referrer_member_id, is_guest,
    business_date, occurred_at, recorded_at, status
)
SELECT
    CONCAT('CLF-', LEFT(SHA2(CONCAT(sf.tenant_id, CHAR(0), 'first-course:', sf.order_id), 256), 40)),
    CONCAT('first-course:', sf.order_id), sf.tenant_id, sf.organization_id,
    sf.store_id, sf.member_id, 'first_course_completed', 'pre_sale', sf.order_id,
    sf.order_no_snapshot, sf.business_source_primary_id,
    sf.business_source_primary_name_snapshot, sf.business_source_secondary_id,
    sf.business_source_secondary_name_snapshot, sf.business_source_label_snapshot,
    sf.source_attribution_type_snapshot, 0, 0, sf.business_date,
    sf.occurred_at, sf.recorded_at, 'effective'
FROM tmp_customer_source_first_course sf;

INSERT INTO eb_cashier_v3_customer_lifecycle_projection (
    tenant_id, member_id, organization_id, store_id, lifecycle_stage,
    first_course_order_id, first_course_order_no_snapshot,
    first_course_completed_at, first_course_business_date,
    first_course_source_primary_id, first_course_source_primary_name_snapshot,
    first_course_source_secondary_id, first_course_source_secondary_name_snapshot,
    first_course_source_label_snapshot,
    first_course_source_attribution_type_snapshot, referrer_member_id,
    is_guest, version, created_at, updated_at
)
SELECT sf.tenant_id, sf.member_id, sf.organization_id, sf.store_id, 'pre_sale',
       sf.order_id, sf.order_no_snapshot, sf.occurred_at, sf.business_date,
       sf.business_source_primary_id, sf.business_source_primary_name_snapshot,
       sf.business_source_secondary_id, sf.business_source_secondary_name_snapshot,
       sf.business_source_label_snapshot, sf.source_attribution_type_snapshot,
       0, 0, 1, sf.recorded_at, sf.recorded_at
FROM tmp_customer_source_first_course sf
LEFT JOIN eb_cashier_v3_customer_lifecycle_projection p
       ON p.tenant_id = sf.tenant_id AND p.member_id = sf.member_id
WHERE p.id IS NULL;

UPDATE eb_cashier_v3_customer_lifecycle_projection p
JOIN tmp_customer_source_first_course sf
  ON sf.tenant_id = p.tenant_id AND sf.member_id = p.member_id
SET p.organization_id = sf.organization_id,
    p.store_id = sf.store_id,
    p.lifecycle_stage = CASE WHEN p.service_visit_count >= 2 THEN 'post_sale' ELSE 'pre_sale' END,
    p.first_course_order_id = sf.order_id,
    p.first_course_order_no_snapshot = sf.order_no_snapshot,
    p.first_course_completed_at = sf.occurred_at,
    p.first_course_business_date = sf.business_date,
    p.first_course_source_primary_id = sf.business_source_primary_id,
    p.first_course_source_primary_name_snapshot = sf.business_source_primary_name_snapshot,
    p.first_course_source_secondary_id = sf.business_source_secondary_id,
    p.first_course_source_secondary_name_snapshot = sf.business_source_secondary_name_snapshot,
    p.first_course_source_label_snapshot = sf.business_source_label_snapshot,
    p.first_course_source_attribution_type_snapshot = sf.source_attribution_type_snapshot,
    p.version = p.version + 1,
    p.updated_at = sf.recorded_at
WHERE p.first_course_order_id = '';

SELECT 'customer_source_lifecycle_backfill' AS apply_note,
       (SELECT COUNT(*) FROM tmp_customer_source_first_course) AS candidate_count,
       (SELECT COUNT(*) FROM eb_cashier_v3_customer_lifecycle_fact
        WHERE event_type = 'first_course_completed') AS lifecycle_fact_count,
       (SELECT COUNT(*) FROM eb_cashier_v3_customer_lifecycle_projection
        WHERE first_course_order_id <> '' AND first_course_source_primary_id > 0) AS attributed_projection_count;

DROP TEMPORARY TABLE IF EXISTS tmp_customer_source_first_course;
