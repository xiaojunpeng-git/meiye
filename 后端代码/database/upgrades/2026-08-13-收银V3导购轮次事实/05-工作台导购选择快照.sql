-- Adds the server-locked workspace snapshot consumed by the guide-round fact writer.
SET @db := DATABASE();
SET @has_column := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME='guide_selections_json');
SET @sql := IF(@has_column=0, 'ALTER TABLE eb_cashier_v3_workspace_line ADD COLUMN guide_selections_json MEDIUMTEXT NULL AFTER salespeople_json', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SELECT 'APPLY_OK' AS apply_result;
