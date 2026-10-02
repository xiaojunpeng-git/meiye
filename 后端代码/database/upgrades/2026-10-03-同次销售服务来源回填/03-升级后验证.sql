-- A successful run leaves no eligible empty-source facts or mismatched audit rows.
SELECT COUNT(*) AS remaining_candidates
FROM eb_cashier_v3_entitlement_service_fact sf
JOIN eb_cashier_v3_sales_order so
  ON so.tenant_id=sf.tenant_id AND so.checkout_request_id=sf.checkout_request_id
  AND so.store_id=sf.store_id AND so.member_id=sf.member_id
WHERE sf.source_document_type<>'reservation' AND sf.checkout_request_id<>''
  AND sf.business_source_primary_id=0 AND sf.business_source_primary_name_snapshot=''
  AND sf.business_source_secondary_id=0 AND sf.business_source_secondary_name_snapshot=''
  AND sf.business_source_label_snapshot='' AND sf.source_attribution_type_snapshot='other'
  AND so.order_status='settled' AND so.order_direction='forward'
  AND so.business_source_primary_id>0 AND so.business_source_primary_name_snapshot<>''
  AND so.business_source_label_snapshot<>''
  AND (so.business_source_secondary_id=0 OR so.business_source_secondary_name_snapshot<>'');

SELECT COUNT(*) AS active_audit_rows,
  SUM(sf.business_source_primary_id<>a.primary_source_id
      OR sf.business_source_primary_name_snapshot<>a.primary_source_name_snapshot
      OR sf.business_source_secondary_id<>a.secondary_source_id
      OR sf.business_source_secondary_name_snapshot<>a.secondary_source_name_snapshot
      OR sf.business_source_label_snapshot<>a.source_label_snapshot
      OR sf.immutable_fingerprint<>a.original_immutable_fingerprint) AS mismatched_rows
FROM eb_cashier_v3_service_source_backfill_audit a
JOIN eb_cashier_v3_entitlement_service_fact sf ON sf.id=a.service_fact_id
WHERE a.rolled_back_at=0;

SELECT COUNT(*) AS upgrade_log_rows FROM eb_database_upgrade_log
WHERE upgrade_key='20261003-001-same-checkout-service-source-backfill';
