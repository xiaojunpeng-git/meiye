-- Run against the selected instance only; this migration does not backfill historical rows.
SELECT DATABASE() AS target_database;
SELECT TABLE_NAME, COUNT(*) AS existing_source_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_debt_repayment', 'eb_cashier_v3_recharge_debt_repayment')
  AND COLUMN_NAME IN ('business_source_primary_id', 'business_source_primary_name_snapshot',
                      'business_source_secondary_id', 'business_source_secondary_name_snapshot',
                      'business_source_label_snapshot')
GROUP BY TABLE_NAME;
