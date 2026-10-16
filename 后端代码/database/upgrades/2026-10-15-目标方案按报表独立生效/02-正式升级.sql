-- MySQL 5.7-compatible, repeatable index change. A report still binds to
-- exactly one scheme per store; equal goal keys can coexist across schemes.
SET @target_new_key_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'eb_target_effective_line'
    AND index_name = 'uk_effective_scheme_identity'
);
SET @target_add_key_sql := IF(@target_new_key_exists = 0,
  'ALTER TABLE eb_target_effective_line ADD UNIQUE KEY uk_effective_scheme_identity (tenant_id, scheme_id, store_id, month_key, metric_code, scope_type, scope_id)',
  'SELECT 1');
PREPARE target_add_key FROM @target_add_key_sql;
EXECUTE target_add_key;
DEALLOCATE PREPARE target_add_key;

SET @target_old_key_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'eb_target_effective_line'
    AND index_name = 'uk_effective_identity'
);
SET @target_drop_key_sql := IF(@target_old_key_exists > 0,
  'ALTER TABLE eb_target_effective_line DROP INDEX uk_effective_identity',
  'SELECT 1');
PREPARE target_drop_key FROM @target_drop_key_sql;
EXECUTE target_drop_key;
DEALLOCATE PREPARE target_drop_key;
