SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = 'eb_target_effective_line'
  AND index_name IN ('uk_effective_identity', 'uk_effective_scheme_identity', 'uk_line')
GROUP BY index_name, non_unique;
SELECT COUNT(*) AS effective_rows FROM eb_target_effective_line;
SELECT report_code, COUNT(*) AS stores, COUNT(DISTINCT scheme_id) AS schemes
FROM eb_target_report_binding
WHERE report_code = 'operations_performance_comparison'
GROUP BY report_code;
