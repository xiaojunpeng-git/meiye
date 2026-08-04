-- upgrade_key: 20260730-022-cashier-v3-sale-hang-resume-checkout-v1
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @hr_db := DATABASE();
SET @hr_failures := 0;

SELECT COUNT(*) INTO @hr_upgrade_log
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@hr_db AND TABLE_NAME='eb_database_upgrade_log' AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @hr_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@hr_db AND ENGINE='InnoDB' AND TABLE_NAME IN (
  'eb_cashier_v3_hang_order',
  'eb_cashier_v3_hang_order_line',
  'eb_cashier_v3_workspace_draft',
  'eb_cashier_v3_workspace_line'
);

SELECT COUNT(*) INTO @hr_base_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@hr_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_hang_order.hang_order_id',
  'eb_cashier_v3_hang_order.hang_status',
  'eb_cashier_v3_hang_order.hang_version',
  'eb_cashier_v3_hang_order_line.hang_order_id',
  'eb_cashier_v3_hang_order_line.workspace_line_id',
  'eb_cashier_v3_hang_order_line.line_snapshot_json',
  'eb_cashier_v3_workspace_draft.workspace_id',
  'eb_cashier_v3_workspace_draft.line_fingerprint',
  'eb_cashier_v3_workspace_line.authority_snapshot_json'
);

SELECT COUNT(*) INTO @hr_target_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@hr_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_cashier_v3_hang_order.resume_contract_version',
  'eb_cashier_v3_hang_order.resume_workspace_id',
  'eb_cashier_v3_hang_order.resume_state_context_id',
  'eb_cashier_v3_hang_order.resume_command_idempotency_key',
  'eb_cashier_v3_hang_order.resumed_at',
  'eb_cashier_v3_hang_order.checkout_request_id',
  'eb_cashier_v3_hang_order.sales_order_id',
  'eb_cashier_v3_hang_order.settled_at',
  'eb_cashier_v3_hang_order_line.workspace_snapshot_json',
  'eb_cashier_v3_workspace_draft.resumed_hang_order_id'
);

SELECT COUNT(*) INTO @hr_valid_target_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@hr_db AND (
  (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_contract_version'
    AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_workspace_id'
    AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_state_context_id'
    AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_command_idempotency_key'
    AND COLUMN_TYPE='varchar(128)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resumed_at'
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='checkout_request_id'
    AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='sales_order_id'
    AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='settled_at'
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (TABLE_NAME='eb_cashier_v3_hang_order_line' AND COLUMN_NAME='workspace_snapshot_json'
    AND DATA_TYPE='longtext' AND IS_NULLABLE='YES')
  OR (TABLE_NAME='eb_cashier_v3_workspace_draft' AND COLUMN_NAME='resumed_hang_order_id'
    AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
);

SET @hr_failures := @hr_failures
  + IF(@hr_upgrade_log=1,0,1)
  + IF(@hr_tables=4,0,1)
  + IF(@hr_base_columns=9,0,1)
  + IF(@hr_target_columns IN (0,10),0,1)
  + IF(@hr_target_columns=0 OR @hr_valid_target_columns=10,0,1);

SELECT IF(@hr_failures=0,'PRECHECK_OK','PRECHECK_FAILED') AS precheck_result,
  @hr_failures AS failure_count,
  @hr_tables AS authority_table_count,
  @hr_target_columns AS existing_target_column_count,
  @hr_valid_target_columns AS valid_target_column_count;

SET @hr_abort_sql := IF(
  @hr_failures=0,
  'SELECT ''PRECHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_CASHIER_V3_HANG_RESUME_PRECHECK_FAILED'
);
PREPARE hr_precheck_stmt FROM @hr_abort_sql;
EXECUTE hr_precheck_stmt;
DEALLOCATE PREPARE hr_precheck_stmt;
