SET NAMES utf8mb4;
SELECT DATABASE() AS target_database;
SELECT TABLE_NAME, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_refund_adjustment', 'eb_cashier_v3_performance_fact',
                     'eb_cashier_v3_refund_salesperson_revision');
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'eb_cashier_v3_refund_adjustment'
        AND COLUMN_NAME IN ('salespeople_snapshot_json', 'salespeople_version',
                            'source_sales_order_id', 'source_sales_line_id'))
       OR (TABLE_NAME = 'eb_cashier_v3_performance_fact'
           AND COLUMN_NAME IN ('order_id', 'performance_type', 'fact_direction', 'reversal_of')));
SELECT COUNT(*) AS existing_refund_deduction_facts
FROM eb_cashier_v3_performance_fact
WHERE performance_type = 'refund_sales_deduction';
