-- upgrade_key: 20260805-003-inventory-v3-manual-document-reversal
SET NAMES utf8mb4;

SELECT COUNT(*) AS reversal_authority_table
FROM information_schema.tables
WHERE table_schema=DATABASE() AND table_name='eb_inventory_manual_document_reversal';

SELECT COUNT(*) AS required_columns
FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='eb_inventory_manual_document_reversal'
  AND column_name IN (
    'tenant_id','source_type','source_id','location_id','store_id','idempotency_key',
    'request_fingerprint','reason','operator_type','operator_id','reversal_status',
    'result_snapshot','business_date','occurred_at','settled_at','recorded_at'
  );

SELECT index_name, non_unique
FROM information_schema.statistics
WHERE table_schema=DATABASE() AND table_name='eb_inventory_manual_document_reversal'
  AND index_name IN ('uk_tenant_document','uk_tenant_idempotency')
GROUP BY index_name,non_unique
ORDER BY index_name;

SELECT COUNT(*) AS invalid_reversal_links
FROM eb_inventory_batch_movement_fact r
LEFT JOIN eb_inventory_batch_movement_fact o ON o.id=r.reversal_of
WHERE r.source_type IN ('manual_inbound_reversal','manual_outbound_reversal')
  AND (r.reversal_of=0 OR o.id IS NULL OR r.direction<>-o.direction
       OR r.quantity_units<>o.quantity_units OR r.unit_cost_cents<>o.unit_cost_cents
       OR r.cost_amount_cents<>o.cost_amount_cents);
