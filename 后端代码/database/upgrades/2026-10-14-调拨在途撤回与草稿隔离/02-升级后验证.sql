SELECT
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_cross_transfer_document' AND column_name='withdrawal_count') AS document_columns,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_cross_transfer_line' AND column_name IN ('line_generation','retired_at')) AS line_columns,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eb_inventory_cross_transfer_batch_allocation' AND column_name IN ('dispatch_attempt','withdrawn_at')) AS allocation_columns,
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_inventory_cross_transfer_line' AND index_name='uk_document_generation_line') AS line_index_parts,
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='eb_inventory_cross_transfer_batch_allocation' AND index_name='uk_document_attempt_batch') AS allocation_index_parts,
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='eb_inventory_cross_transfer_lifecycle_operation') AS operation_tables;

SELECT COUNT(*) AS bad_active_allocations
FROM eb_inventory_cross_transfer_batch_allocation
WHERE received_at>0 AND withdrawn_at>0;
