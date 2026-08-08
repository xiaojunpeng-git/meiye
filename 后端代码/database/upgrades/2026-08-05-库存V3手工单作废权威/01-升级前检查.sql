-- upgrade_key: 20260805-003-inventory-v3-manual-document-reversal
SET NAMES utf8mb4;

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema=DATABASE()
  AND table_name IN ('eb_inventory_batch_movement_fact','eb_inventory_business_document_no');

SELECT source_type, COUNT(*) AS document_count
FROM (
  SELECT source_type, source_id
  FROM eb_inventory_batch_movement_fact
  WHERE fact_status='SETTLED' AND reversal_of=0
    AND source_type IN ('manual_inbound','manual_outbound')
  GROUP BY source_type, source_id
) d
GROUP BY source_type;

