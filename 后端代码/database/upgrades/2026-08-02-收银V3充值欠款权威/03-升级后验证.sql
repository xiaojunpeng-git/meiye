-- upgrade_key: 20260802-001-cashier-v3-recharge-debt-authority
-- Read-only postcheck; MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rcd_db := DATABASE();

SELECT COUNT(*) INTO @rcd_table_ok
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rcd_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_authority' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @rcd_columns_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rcd_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_authority'
  AND COLUMN_NAME IN ('debt_id','debt_no','tenant_id','store_id','member_id','recharge_id',
    'recharge_order_no_snapshot','command_idempotency_key','policy_version','authority_fingerprint','created_at','updated_at');
SELECT COUNT(*) INTO @rcd_indexes_ok
FROM (
  SELECT DISTINCT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@rcd_db AND TABLE_NAME='eb_cashier_v3_recharge_debt_authority'
    AND INDEX_NAME IN ('PRIMARY','uk_debt_id','uk_tenant_recharge','uk_tenant_command','idx_scope_member')
) rcd_indexes;
SELECT @rcd_table_ok AS authority_table_count,@rcd_columns_ok AS authority_column_count,
  @rcd_indexes_ok AS authority_index_count;
SET @rcd_abort := IF(@rcd_table_ok=1 AND @rcd_columns_ok=12 AND @rcd_indexes_ok=5,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_RECHARGE_DEBT_POSTCHECK_FAILED');
PREPARE rcd_stmt FROM @rcd_abort; EXECUTE rcd_stmt; DEALLOCATE PREPARE rcd_stmt;
