SELECT DATABASE() AS database_name,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_project_performance_rule' AND COLUMN_NAME='craftsman_default_unit_amount_cents' AND IS_NULLABLE='YES') AS project_ready,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='craftsman_default_mode' AND COLUMN_DEFAULT='default_unit_price') AS card_mode_ready,
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='craftsman_default_unit_amount_cents') AS card_amount_ready,
  (SELECT COUNT(*) FROM eb_store_card_related WHERE craftsman_default_mode NOT IN ('default_unit_price','manual_amount')) AS invalid_card_modes;
