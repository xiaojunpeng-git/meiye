-- Read only. Confirm that the scheme-scoped key is safe before changing the index.
SELECT DATABASE() AS database_name, VERSION() AS mysql_version;
SELECT COUNT(*) AS effective_rows FROM eb_target_effective_line;
SELECT tenant_id, scheme_id, store_id, month_key, metric_code, scope_type, scope_id, COUNT(*) AS duplicates
FROM eb_target_effective_line
GROUP BY tenant_id, scheme_id, store_id, month_key, metric_code, scope_type, scope_id
HAVING COUNT(*) > 1;
SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'eb_target_effective_line'
  AND index_name IN ('uk_effective_identity', 'uk_effective_scheme_identity', 'uk_line')
GROUP BY index_name, non_unique;
