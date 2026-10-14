SET NAMES utf8mb4;
SELECT DATABASE() AS database_name;
SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND (
   (TABLE_NAME='eb_store_card_related' AND COLUMN_NAME LIKE 'combination_%') OR
   (TABLE_NAME='eb_cashier_v3_card_rule_component' AND COLUMN_NAME='combination_group_no'))
 ORDER BY TABLE_NAME,COLUMN_NAME;
SELECT COUNT(*) AS group_state_table_count FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_card_rule_group_state';
