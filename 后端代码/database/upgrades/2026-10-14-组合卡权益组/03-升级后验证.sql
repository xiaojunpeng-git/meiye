SET NAMES utf8mb4;
SELECT DATABASE() AS database_name,
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME LIKE 'combination_%') AS configuration_columns,
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_card_rule_component' AND COLUMN_NAME='combination_group_no') AS component_group_columns,
 (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_card_rule_group_state') AS group_state_tables,
 (SELECT COUNT(*) FROM eb_store_card_related WHERE combination_group_no<>0) AS configured_group_rows,
 (SELECT COUNT(*) FROM eb_cashier_v3_card_rule_group_state) AS issued_group_rows;
