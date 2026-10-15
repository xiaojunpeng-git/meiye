SELECT DATABASE() AS database_name,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_project_performance_rule' AND COLUMN_NAME='craftsman_default_unit_amount_cents') AS project_column_count,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='craftsman_default_mode') AS card_mode_column_count,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='craftsman_default_unit_amount_cents') AS card_amount_column_count,
  (SELECT COUNT(*) FROM eb_store_card_related) AS card_relation_rows;
