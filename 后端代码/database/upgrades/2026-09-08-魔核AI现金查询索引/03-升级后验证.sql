SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS indexed_columns, COUNT(*) AS column_count, MIN(NON_UNIQUE) AS non_unique, SUM(SUB_PART IS NOT NULL) AS prefix_column_count FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_order_center_void_operation' AND INDEX_NAME='idx_ai_cash_source_guard' GROUP BY INDEX_NAME;
-- Expected: exactly one row, tenant_id,source_kind,source_id,status; 4 columns;
-- non_unique=1 and prefix_column_count=0. Separately record real-scope EXPLAIN.
