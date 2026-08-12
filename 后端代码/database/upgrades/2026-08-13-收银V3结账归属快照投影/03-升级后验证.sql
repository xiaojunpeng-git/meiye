SET @db := DATABASE();
SELECT COUNT(*) AS attribution_projection_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft'
  AND COLUMN_NAME IN ('guide_selections_json','sales_manager_selections_json');
