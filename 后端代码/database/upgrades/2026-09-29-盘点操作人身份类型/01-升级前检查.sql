-- upgrade_key: 20260929-001-inventory-count-operator-type
-- 两张表都必须已存在；版本与备份由逐实例发布清单另外核实。
SELECT table_name FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('eb_inventory_stock_count_draft', 'eb_inventory_stock_count_document');
SELECT table_name, column_name FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('eb_inventory_stock_count_draft', 'eb_inventory_stock_count_document')
  AND column_name IN ('operator_id', 'operator_type');
