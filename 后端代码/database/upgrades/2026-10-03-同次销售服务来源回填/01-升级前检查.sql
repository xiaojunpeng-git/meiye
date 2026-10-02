-- Read-only preflight. Both counts must be reviewed for the named instance.
SELECT DATABASE() AS target_database;
SELECT TABLE_NAME, COUNT(*) AS source_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_sales_order','eb_cashier_v3_entitlement_service_fact')
  AND COLUMN_NAME IN ('business_source_primary_id','business_source_primary_name_snapshot',
    'business_source_secondary_id','business_source_secondary_name_snapshot','business_source_label_snapshot')
GROUP BY TABLE_NAME;

SELECT COUNT(*) AS same_checkout_empty_source_candidates
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
