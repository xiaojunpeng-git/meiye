SET NAMES utf8mb4;

SELECT CASE WHEN COUNT(*)=7 THEN 'PASS' ELSE 'FAIL' END AS schema_check
FROM information_schema.columns
WHERE table_schema=DATABASE()
  AND ((table_name='eb_inventory_batch_movement_fact' AND column_name IN ('operator_type','operator_id','operator_name_snapshot'))
    OR (table_name='eb_inventory_stock_count_document' AND column_name='operator_name_snapshot')
    OR (table_name='eb_inventory_cross_transfer_document' AND column_name='created_by_operator_name_snapshot')
    OR (table_name='eb_inventory_salon_usage_document' AND column_name='operator_name_snapshot')
    OR (table_name='eb_inventory_v3_import_record' AND column_name='operator_name_snapshot'));

SELECT COUNT(*) AS invalid_new_fact_actor_rows
FROM eb_inventory_batch_movement_fact
WHERE operator_id>0 AND operator_name_snapshot='';
