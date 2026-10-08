SET NAMES utf8mb4;
SELECT DATABASE() AS target_database;
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_adjustment'
  AND COLUMN_NAME IN ('salespeople_snapshot_json', 'salespeople_version',
                      'source_sales_order_id', 'source_sales_line_id');
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_salesperson_revision';
SELECT COUNT(*) AS invalid_refund_salesperson_versions
FROM eb_cashier_v3_refund_adjustment
WHERE salespeople_version > 0
  AND (salespeople_snapshot_json IS NULL OR JSON_VALID(salespeople_snapshot_json) = 0);
SELECT COUNT(*) AS orphan_refund_deduction_facts
FROM eb_cashier_v3_performance_fact pf
LEFT JOIN eb_cashier_v3_refund_adjustment ra
  ON ra.tenant_id = pf.tenant_id AND ra.operation_id = pf.order_id
WHERE pf.performance_type = 'refund_sales_deduction' AND ra.id IS NULL;
