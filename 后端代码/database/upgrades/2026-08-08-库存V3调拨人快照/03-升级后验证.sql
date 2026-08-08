-- 仅检查，不修改数据。
SELECT COUNT(*) AS transfer_person_columns
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'eb_inventory_cross_transfer_document'
  AND column_name IN ('transfer_staff_id', 'transfer_employee_id', 'transfer_staff_name_snapshot');

SELECT COUNT(*) AS invalid_named_transfer_rows
FROM eb_inventory_cross_transfer_document
WHERE (transfer_staff_id = 0 AND transfer_employee_id <> 0)
   OR (transfer_staff_id > 0 AND transfer_staff_name_snapshot = '');
