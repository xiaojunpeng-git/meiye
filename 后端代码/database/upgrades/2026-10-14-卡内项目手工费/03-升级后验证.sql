SET NAMES utf8mb4;
SELECT DATABASE() AS database_name,
       (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='labor_fee_cents' AND IS_NULLABLE='NO') AS ready_column_count,
       (SELECT COUNT(*) FROM eb_store_card_related WHERE labor_fee_cents IS NULL) AS missing_rows,
       (SELECT COUNT(*) FROM eb_store_card_related WHERE product_type=6) AS configured_project_rows;

