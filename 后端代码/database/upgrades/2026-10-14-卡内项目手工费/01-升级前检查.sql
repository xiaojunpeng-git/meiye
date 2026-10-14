SET NAMES utf8mb4;
SELECT DATABASE() AS database_name,
       (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='labor_fee_cents') AS labor_column_count,
       (SELECT COUNT(*) FROM eb_store_card_related WHERE product_type=6) AS existing_card_project_rows,
       (SELECT COUNT(*) FROM eb_store_product WHERE product_type=5 AND is_del=0) AS existing_cards;

