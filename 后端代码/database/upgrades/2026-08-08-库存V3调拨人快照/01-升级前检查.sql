-- 仅检查，不修改数据。
SET @transfer_person_columns := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'eb_inventory_cross_transfer_document'
    AND column_name IN ('transfer_staff_id', 'transfer_employee_id', 'transfer_staff_name_snapshot')
);

SELECT
  @transfer_person_columns AS existing_transfer_person_columns,
  IF(@transfer_person_columns = 0, 'PASS', 'STOP_ALREADY_APPLIED_OR_PARTIAL') AS upgrade_guard;
