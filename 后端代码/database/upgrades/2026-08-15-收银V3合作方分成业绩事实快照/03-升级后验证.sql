-- upgrade_key: 20260815-003-cashier-v3-partner-share-fact-snapshot
SET NAMES utf8mb4;
SET @partner_share_db := DATABASE();

SELECT TABLE_NAME, COUNT(*) AS required_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@partner_share_db
  AND TABLE_NAME IN ('eb_cashier_v3_report_sale_dimension_fact','eb_cashier_v3_card_sale_category_allocation_fact')
  AND COLUMN_NAME IN (
    'partner_category_id_snapshot',
    'partner_category_name_snapshot',
    'partner_category_path_snapshot',
    'partner_default_ratio_snapshot',
    'partner_config_version_snapshot',
    'partner_share_amount_cents'
  )
GROUP BY TABLE_NAME;

SELECT TABLE_NAME, INDEX_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@partner_share_db
  AND TABLE_NAME IN ('eb_cashier_v3_report_sale_dimension_fact','eb_cashier_v3_card_sale_category_allocation_fact')
  AND INDEX_NAME='idx_scope_partner_category'
GROUP BY TABLE_NAME, INDEX_NAME;

SELECT COUNT(*) AS invalid_ratio_snapshots
FROM (
  SELECT partner_default_ratio_snapshot AS ratio FROM eb_cashier_v3_report_sale_dimension_fact
  UNION ALL
  SELECT partner_default_ratio_snapshot AS ratio FROM eb_cashier_v3_card_sale_category_allocation_fact
) partner_ratio_snapshots
WHERE ratio < 0 OR ratio > 100;
