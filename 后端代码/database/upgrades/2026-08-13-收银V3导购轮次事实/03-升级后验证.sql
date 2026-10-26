-- upgrade_key: 20260813-001-cashier-v3-guide-round-fact
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @gr_db := DATABASE();
SET @gr_target_table_count := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=@gr_db AND TABLE_NAME='eb_cashier_v3_customer_guide_round_fact' AND ENGINE='InnoDB'
);
SET @gr_required_column_count := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@gr_db AND TABLE_NAME='eb_cashier_v3_customer_guide_round_fact'
    AND COLUMN_NAME IN ('tenant_id','organization_id','store_id','member_id','order_id','source_line_id','business_date','guide_round_no','guide_employee_id','guide_employee_name_snapshot','command_idempotency_key','immutable_fingerprint')
);
SET @gr_required_unique_index_count := (
  SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@gr_db AND TABLE_NAME='eb_cashier_v3_customer_guide_round_fact'
    AND INDEX_NAME IN ('uk_tenant_natural','uk_tenant_fact')
);
SELECT @gr_target_table_count AS target_table_count;
SELECT @gr_required_column_count AS required_column_count;
SELECT @gr_required_unique_index_count AS required_unique_index_count;
SELECT CASE WHEN @gr_target_table_count = 1 AND @gr_required_column_count = 12 AND @gr_required_unique_index_count = 2
  THEN 'POSTCHECK_OK' ELSE 'POSTCHECK_FAILURE' END AS postcheck_result;
SELECT COUNT(*) AS workspace_guide_snapshot_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@gr_db AND TABLE_NAME='eb_cashier_v3_workspace_line' AND COLUMN_NAME='guide_selections_json';
