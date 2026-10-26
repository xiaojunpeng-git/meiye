-- upgrade_key: 20260730-002-cashier-v3-sale-inventory-settlement-v1
-- Read-only exact receipt schema validation. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @siv_db := DATABASE();
SET @siv_failures := 0;

SELECT COUNT(*) INTO @siv_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@siv_db AND TABLE_NAME='eb_cashier_v3_sale_inventory_receipt'
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @siv_sku_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@siv_db AND (
  (TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='catalog_sku_id'
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='catalog_sku_id'
    AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
);

SELECT COUNT(*) INTO @siv_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@siv_db AND TABLE_NAME='eb_cashier_v3_sale_inventory_receipt' AND (
  (COLUMN_NAME='id' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND EXTRA LIKE '%auto_increment%')
  OR (COLUMN_NAME='receipt_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='command_idempotency_key' AND COLUMN_TYPE='varchar(128)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='plan_fingerprint' AND COLUMN_TYPE='char(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='contract_version' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='tenant_id' AND COLUMN_TYPE='varchar(32)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='organization_id' AND COLUMN_TYPE='varchar(32)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='organization_path_snapshot' AND COLUMN_TYPE='varchar(191)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (COLUMN_NAME='organization_name_snapshot' AND COLUMN_TYPE='varchar(128)' AND CHARACTER_SET_NAME='utf8mb4' AND COLLATION_NAME='utf8mb4_general_ci' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (COLUMN_NAME='store_id' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='store_name_snapshot' AND COLUMN_TYPE='varchar(128)' AND CHARACTER_SET_NAME='utf8mb4' AND COLLATION_NAME='utf8mb4_general_ci' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='')
  OR (COLUMN_NAME='operator_id' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='checkout_request_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='sales_order_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='actual_cost_cents' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (COLUMN_NAME='allocation_count' AND COLUMN_TYPE='int(10) unsigned' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0')
  OR (COLUMN_NAME='result_snapshot' AND DATA_TYPE='mediumtext' AND CHARACTER_SET_NAME='utf8mb4' AND COLLATION_NAME='utf8mb4_general_ci' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='business_date' AND DATA_TYPE='date' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='occurred_at' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='settled_at' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='recorded_at' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='add_time' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO')
  OR (COLUMN_NAME='update_time' AND COLUMN_TYPE='bigint(20) unsigned' AND IS_NULLABLE='NO')
);

SELECT COUNT(*) INTO @siv_column_total
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@siv_db AND TABLE_NAME='eb_cashier_v3_sale_inventory_receipt';

SELECT COUNT(*) INTO @siv_column_order
FROM (
  SELECT GROUP_CONCAT(CONCAT(ORDINAL_POSITION,':',COLUMN_NAME) ORDER BY ORDINAL_POSITION SEPARATOR ',') AS signature
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@siv_db AND TABLE_NAME='eb_cashier_v3_sale_inventory_receipt'
) siv_columns
WHERE signature='1:id,2:receipt_id,3:command_idempotency_key,4:plan_fingerprint,5:contract_version,6:tenant_id,7:organization_id,8:organization_path_snapshot,9:organization_name_snapshot,10:store_id,11:store_name_snapshot,12:operator_id,13:checkout_request_id,14:sales_order_id,15:actual_cost_cents,16:allocation_count,17:result_snapshot,18:business_date,19:occurred_at,20:settled_at,21:recorded_at,22:add_time,23:update_time';

SELECT COUNT(*) INTO @siv_primary_key
FROM (
  SELECT INDEX_NAME,
    GROUP_CONCAT(CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME,':',IFNULL(SUB_PART,0)) ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS signature
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@siv_db AND TABLE_NAME='eb_cashier_v3_sale_inventory_receipt'
    AND INDEX_NAME='PRIMARY'
  GROUP BY INDEX_NAME
) siv_primary
WHERE signature='1:id:0';

SELECT COUNT(*) INTO @siv_unique_indexes
FROM (
  SELECT INDEX_NAME,
    GROUP_CONCAT(CONCAT(SEQ_IN_INDEX,':',COLUMN_NAME,':',IFNULL(SUB_PART,0)) ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS signature
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@siv_db AND TABLE_NAME='eb_cashier_v3_sale_inventory_receipt'
    AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY'
  GROUP BY INDEX_NAME
) siv_unique
WHERE (INDEX_NAME='uk_tenant_receipt' AND signature='1:tenant_id:0,2:receipt_id:0')
   OR (INDEX_NAME='uk_tenant_checkout' AND signature='1:tenant_id:0,2:checkout_request_id:0')
   OR (INDEX_NAME='uk_tenant_command' AND signature='1:tenant_id:0,2:command_idempotency_key:0');

SELECT COUNT(*) INTO @siv_unique_index_total
FROM (
  SELECT INDEX_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@siv_db AND TABLE_NAME='eb_cashier_v3_sale_inventory_receipt'
    AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY'
  GROUP BY INDEX_NAME
) siv_unique_total;

SET @siv_failures := @siv_failures
  + IF(@siv_table=1,0,1)
  + IF(@siv_sku_columns=2,0,1)
  + IF(@siv_columns=23,0,1)
  + IF(@siv_column_total=23,0,1)
  + IF(@siv_column_order=1,0,1)
  + IF(@siv_primary_key=1,0,1)
  + IF(@siv_unique_indexes=3,0,1)
  + IF(@siv_unique_index_total=3,0,1);

SELECT IF(@siv_failures=0,'POSTCHECK_OK','POSTCHECK_FAILED') AS postcheck_result,
  @siv_failures AS failure_count,
  @siv_table AS target_table_count,
  @siv_sku_columns AS frozen_sku_column_count,
  @siv_columns AS required_business_column_count,
  @siv_column_total AS column_total,
  @siv_column_order AS column_order_count,
  @siv_primary_key AS primary_key_count,
  @siv_unique_indexes AS required_unique_index_count,
  @siv_unique_index_total AS unique_index_total;

SET @siv_abort_sql := IF(
  @siv_failures=0,
  'SELECT ''POSTCHECK_CONTINUE'' AS gate',
  'SELECT * FROM STOP_CASHIER_V3_SALE_INVENTORY_POSTCHECK_FAILED'
);
PREPARE siv_postcheck_stmt FROM @siv_abort_sql;
EXECUTE siv_postcheck_stmt;
DEALLOCATE PREPARE siv_postcheck_stmt;
