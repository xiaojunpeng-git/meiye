-- upgrade_key: 20260731-005-cashier-v3-custom-card-configuration
-- Read-only postcheck; MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ccc_db := DATABASE();

SELECT COUNT(*) INTO @ccc_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ccc_db AND TABLE_NAME='eb_cashier_v3_custom_card_configuration'
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';
SELECT COUNT(*) INTO @ccc_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ccc_db AND TABLE_NAME='eb_cashier_v3_custom_card_configuration';
SELECT COUNT(*) INTO @ccc_indexes
FROM (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@ccc_db AND TABLE_NAME='eb_cashier_v3_custom_card_configuration'
    AND INDEX_NAME IN ('uk_tenant_configuration','uk_tenant_command','uk_tenant_workspace_line','idx_scope_status','idx_member_status','idx_checkout')
  GROUP BY INDEX_NAME
) ccc_indexes;
SELECT COUNT(*) INTO @ccc_invalid
FROM eb_cashier_v3_custom_card_configuration
WHERE configuration_id NOT REGEXP '^CCD-[A-F0-9]{40}$' OR tenant_id='' OR store_id=0
  OR workspace_id='' OR workspace_line_key='' OR member_id=0 OR card_name_snapshot=''
  OR validity_end_at=0 OR activate_on_purchase NOT IN (0,1) OR total_amount_cents=0
  OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$' OR status NOT IN ('in_cart','settled','cancelled')
  OR resource_version=0 OR created_command_idempotency_key='' OR update_time<add_time;

SELECT @ccc_table AS table_count, @ccc_columns AS column_count,
       @ccc_indexes AS required_index_count, @ccc_invalid AS invalid_row_count;
SET @ccc_abort := IF(@ccc_table=1 AND @ccc_columns=22 AND @ccc_indexes=6 AND @ccc_invalid=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''cashier custom card configuration postcheck failed''');
PREPARE ccc_stmt FROM @ccc_abort; EXECUTE ccc_stmt; DEALLOCATE PREPARE ccc_stmt;
