-- Both order authorities must expose the five checkout-source snapshot columns.
SELECT TABLE_NAME, COUNT(*) AS source_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_debt_repayment', 'eb_cashier_v3_recharge_debt_repayment')
  AND COLUMN_NAME IN ('business_source_primary_id', 'business_source_primary_name_snapshot',
                      'business_source_secondary_id', 'business_source_secondary_name_snapshot',
                      'business_source_label_snapshot')
GROUP BY TABLE_NAME;

SELECT upgrade_key, executed_at, result_note FROM eb_database_upgrade_log
WHERE upgrade_key='20261003-001-cashier-v3-repayment-source-snapshot';
