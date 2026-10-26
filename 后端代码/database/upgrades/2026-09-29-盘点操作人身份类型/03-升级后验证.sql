-- upgrade_key: 20260929-001-inventory-count-operator-type
SHOW COLUMNS FROM `eb_inventory_stock_count_draft` LIKE 'operator_type';
SHOW COLUMNS FROM `eb_inventory_stock_count_document` LIKE 'operator_type';
SHOW INDEX FROM `eb_inventory_stock_count_draft` WHERE Key_name = 'idx_typed_creator';
SELECT `operator_type`, COUNT(*) AS draft_count FROM `eb_inventory_stock_count_draft` GROUP BY `operator_type`;
SELECT `operator_type`, COUNT(*) AS document_count FROM `eb_inventory_stock_count_document` GROUP BY `operator_type`;
