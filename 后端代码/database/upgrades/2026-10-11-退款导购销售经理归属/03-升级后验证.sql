SELECT COUNT(*) AS table_ready
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_attribution';
SELECT COUNT(*) AS required_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_refund_attribution'
  AND COLUMN_NAME IN ('operation_id','role','employee_id','employee_name_snapshot','source_sales_order_id','source_sales_line_id');
SELECT COUNT(*) AS duplicate_refund_role_employee
FROM (SELECT tenant_id,operation_id,role,employee_id FROM eb_cashier_v3_refund_attribution
  GROUP BY tenant_id,operation_id,role,employee_id HAVING COUNT(*) > 1) duplicates;
