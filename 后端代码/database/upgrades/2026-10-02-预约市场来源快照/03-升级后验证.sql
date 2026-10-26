SET @source_db := DATABASE();

SELECT TABLE_NAME, COUNT(*) AS source_snapshot_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@source_db
  AND TABLE_NAME IN ('eb_cashier_v3_reservation','eb_cashier_v3_entitlement_service_fact')
  AND COLUMN_NAME IN (
    'business_source_primary_id','business_source_primary_name_snapshot',
    'business_source_secondary_id','business_source_secondary_name_snapshot',
    'business_source_label_snapshot','source_attribution_type_snapshot'
  )
GROUP BY TABLE_NAME;

SELECT COUNT(*) AS upgrade_log_rows
FROM eb_database_upgrade_log
WHERE upgrade_key='20261002-001-reservation-market-source-snapshot';
