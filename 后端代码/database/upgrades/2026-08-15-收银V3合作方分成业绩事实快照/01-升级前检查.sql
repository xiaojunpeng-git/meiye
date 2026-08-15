-- upgrade_key: 20260815-003-cashier-v3-partner-share-fact-snapshot
SET NAMES utf8mb4;
SET @partner_share_db := DATABASE();

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@partner_share_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_report_sale_dimension_fact',
    'eb_cashier_v3_card_sale_category_allocation_fact',
    'eb_cashier_v3_report_category_config'
  );

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@partner_share_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_report_sale_dimension_fact',
    'eb_cashier_v3_card_sale_category_allocation_fact'
  )
  AND COLUMN_NAME IN (
    'cash_performance_amount_cents',
    'partner_category_id_snapshot',
    'partner_category_name_snapshot',
    'partner_category_path_snapshot',
    'partner_default_ratio_snapshot',
    'partner_config_version_snapshot',
    'partner_share_amount_cents'
  )
ORDER BY TABLE_NAME, COLUMN_NAME;
