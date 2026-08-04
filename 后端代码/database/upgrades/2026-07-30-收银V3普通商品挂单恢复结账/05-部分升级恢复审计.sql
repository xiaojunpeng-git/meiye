-- upgrade_key: 20260730-022-cashier-v3-sale-hang-resume-checkout-v1
-- Read-only recovery audit. Never repairs, deletes or changes business data.
SET NAMES utf8mb4;

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (
  'eb_cashier_v3_hang_order', 'eb_cashier_v3_hang_order_line', 'eb_cashier_v3_workspace_draft'
)
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND (
  (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME IN ('resume_contract_version','resume_workspace_id','resume_state_context_id','resume_command_idempotency_key','resumed_at','checkout_request_id','sales_order_id','settled_at'))
  OR (TABLE_NAME='eb_cashier_v3_hang_order_line' AND COLUMN_NAME='workspace_snapshot_json')
  OR (TABLE_NAME='eb_cashier_v3_workspace_draft' AND COLUMN_NAME='resumed_hang_order_id')
)
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE() AND (
  (TABLE_NAME='eb_cashier_v3_hang_order' AND INDEX_NAME IN ('idx_resume_workspace_status','idx_checkout_request'))
  OR (TABLE_NAME='eb_cashier_v3_workspace_draft' AND INDEX_NAME='idx_resumed_hang_order')
)
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT hang_status, resume_contract_version, COUNT(*) AS row_count
FROM eb_cashier_v3_hang_order
GROUP BY hang_status, resume_contract_version
ORDER BY hang_status, resume_contract_version;
