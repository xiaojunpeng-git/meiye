-- upgrade_key: 20260729-011-cashier-v3-sales-order-authority-v1
-- Read-only exact schema and data verification. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @so_db := DATABASE();
SET @so_failures := 0;

SELECT COUNT(*) INTO @so_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@so_db
  AND TABLE_NAME IN ('eb_cashier_v3_sales_order','eb_cashier_v3_sales_order_line')
  AND ENGINE='InnoDB' AND TABLE_COLLATION='utf8mb4_general_ci';

SELECT COUNT(*) INTO @so_header_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@so_db AND TABLE_NAME='eb_cashier_v3_sales_order';
SELECT COUNT(*) INTO @so_line_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@so_db AND TABLE_NAME='eb_cashier_v3_sales_order_line';

SELECT COUNT(*) INTO @so_header_indexes FROM (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@so_db AND TABLE_NAME='eb_cashier_v3_sales_order'
  GROUP BY INDEX_NAME
) so_header_index_names;
SELECT COUNT(*) INTO @so_line_indexes FROM (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@so_db AND TABLE_NAME='eb_cashier_v3_sales_order_line'
  GROUP BY INDEX_NAME
) so_line_index_names;

SELECT COUNT(*) INTO @so_critical_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@so_db AND (
  (TABLE_NAME='eb_cashier_v3_sales_order' AND (
    (COLUMN_NAME='order_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='natural_key' AND COLUMN_TYPE='varchar(160)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='immutable_fingerprint' AND COLUMN_TYPE='char(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='member_id' AND COLUMN_TYPE='bigint(20) unsigned' AND COLUMN_DEFAULT='0')
    OR (COLUMN_NAME='business_date' AND DATA_TYPE='date' AND IS_NULLABLE='NO')
    OR (COLUMN_NAME='settled_at' AND COLUMN_TYPE='bigint(20) unsigned')
    OR (COLUMN_NAME='order_version' AND COLUMN_TYPE='bigint(20) unsigned')
  )) OR (TABLE_NAME='eb_cashier_v3_sales_order_line' AND (
    (COLUMN_NAME='order_line_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='natural_key' AND COLUMN_TYPE='varchar(160)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='immutable_fingerprint' AND COLUMN_TYPE='char(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='checkout_line_id' AND COLUMN_TYPE='varchar(64)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='item_type' AND COLUMN_TYPE='varchar(16)' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='member_id' AND COLUMN_TYPE='bigint(20) unsigned' AND COLUMN_DEFAULT='0')
    OR (COLUMN_NAME='line_version' AND COLUMN_TYPE='bigint(20) unsigned')
  ))
);

SELECT COUNT(*) INTO @so_invalid_headers
FROM eb_cashier_v3_sales_order
WHERE contract_version<>'cashier-v3-sales-order-authority-v1'
   OR order_status<>'settled'
   OR order_direction<>'forward'
   OR order_version<>1
   OR reversal_of_order_id<>''
   OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR checkout_authority_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR checkout_aggregate_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR checkout_source_set_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR checkout_request_version=0
   OR occurred_at=0 OR settled_at<occurred_at OR recorded_at<settled_at
   OR original_amount_cents<discount_amount_cents
   OR original_amount_cents-discount_amount_cents<>sale_amount_cents
   OR composition NOT IN ('sale_only','mixed')
   OR line_count=0;

SELECT COUNT(*) INTO @so_invalid_lines
FROM eb_cashier_v3_sales_order_line
WHERE item_type NOT IN ('product','card','project')
   OR item_type_name_snapshot NOT IN ('产品','卡项','项目')
   OR line_status<>'settled'
   OR line_direction<>'forward'
   OR line_version<>1
   OR reversal_of_line_id<>''
   OR quantity=0 OR item_version=0 OR checkout_request_version=0
   OR immutable_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR checkout_line_fingerprint NOT REGEXP '^[0-9a-f]{64}$'
   OR original_amount_cents<discount_amount_cents
   OR original_amount_cents-discount_amount_cents<>sale_amount_cents;

SELECT COUNT(*) INTO @so_orphan_lines
FROM eb_cashier_v3_sales_order_line line_row
LEFT JOIN eb_cashier_v3_sales_order header
  ON header.tenant_id=line_row.tenant_id AND header.order_id=line_row.order_id
WHERE header.id IS NULL
   OR header.checkout_request_id<>line_row.checkout_request_id
   OR header.checkout_request_version<>line_row.checkout_request_version
   OR header.store_id<>line_row.store_id
   OR header.member_id<>line_row.member_id
   OR header.command_idempotency_key<>line_row.command_idempotency_key;

SELECT COUNT(*) INTO @so_header_line_mismatches
FROM eb_cashier_v3_sales_order header
LEFT JOIN (
  SELECT tenant_id,order_id,COUNT(*) AS line_count,SUM(quantity) AS total_quantity,
    SUM(original_amount_cents) AS original_amount_cents,
    SUM(discount_amount_cents) AS discount_amount_cents,
    SUM(sale_amount_cents) AS sale_amount_cents
  FROM eb_cashier_v3_sales_order_line
  GROUP BY tenant_id,order_id
) totals ON totals.tenant_id=header.tenant_id AND totals.order_id=header.order_id
WHERE header.line_count<>IFNULL(totals.line_count,0)
   OR header.total_quantity<>IFNULL(totals.total_quantity,0)
   OR header.original_amount_cents<>IFNULL(totals.original_amount_cents,0)
   OR header.discount_amount_cents<>IFNULL(totals.discount_amount_cents,0)
   OR header.sale_amount_cents<>IFNULL(totals.sale_amount_cents,0)
   OR header.line_count=0;

SET @so_failures := @so_failures
  + IF(@so_target_tables=2,0,1)
  + IF(@so_header_columns=44,0,1)
  + IF(@so_line_columns=32,0,1)
  + IF(@so_header_indexes=11,0,1)
  + IF(@so_line_indexes=9,0,1)
  + IF(@so_critical_columns=14,0,1)
  + IF(@so_invalid_headers=0,0,1)
  + IF(@so_invalid_lines=0,0,1)
  + IF(@so_orphan_lines=0,0,1)
  + IF(@so_header_line_mismatches=0,0,1);

SELECT
  @so_target_tables AS exact_target_table_count,
  @so_header_columns AS header_column_count,
  @so_line_columns AS line_column_count,
  @so_header_indexes AS header_index_count,
  @so_line_indexes AS line_index_count,
  @so_critical_columns AS exact_critical_column_count,
  @so_invalid_headers AS invalid_header_count,
  @so_invalid_lines AS invalid_line_count,
  @so_orphan_lines AS orphan_line_count,
  @so_header_line_mismatches AS header_line_mismatch_count,
  @so_failures AS postcheck_failure_count;

SET @so_finish_sql := IF(
  @so_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_SALES_ORDER_POSTCHECK_FAILED'
);
PREPARE so_finish_stmt FROM @so_finish_sql;
EXECUTE so_finish_stmt;
DEALLOCATE PREPARE so_finish_stmt;
