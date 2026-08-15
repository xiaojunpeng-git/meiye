-- upgrade_key: 20260815-002-card-sale-component-category-fact
SET NAMES utf8mb4;
SELECT DATABASE() AS database_name;
SELECT COUNT(*) AS existing_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact';
SELECT COUNT(*) AS card_receipt_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_card_purchase_receipt';
