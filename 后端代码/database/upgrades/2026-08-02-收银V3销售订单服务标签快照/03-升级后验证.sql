-- upgrade_key: 20260802-001-cashier-v3-sales-order-service-tags-v1
-- Read-only exact schema and row-value verification. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @sot_db := DATABASE();
SET @sot_failures := 0;

SELECT COUNT(*) INTO @sot_tag_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sot_db AND TABLE_NAME='eb_cashier_v3_sales_order_line'
  AND ((COLUMN_NAME='service_object' AND COLUMN_TYPE='varchar(16)' AND IS_NULLABLE='NO'
        AND COLUMN_DEFAULT='' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='is_experience' AND COLUMN_TYPE='tinyint(3) unsigned' AND IS_NULLABLE='NO'
        AND COLUMN_DEFAULT='0'));

SELECT COUNT(*) INTO @sot_total_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@sot_db AND TABLE_NAME='eb_cashier_v3_sales_order_line';

SELECT COUNT(*) INTO @sot_invalid_rows
FROM eb_cashier_v3_sales_order_line
WHERE service_object NOT IN ('','self','friend')
   OR is_experience NOT IN (0,1)
   OR (item_type<>'project' AND (service_object<>'' OR is_experience<>0));

SET @sot_failures := @sot_failures
  + IF(@sot_tag_columns=2,0,1)
  + IF(@sot_total_columns=35,0,1)
  + IF(@sot_invalid_rows=0,0,1);

SELECT @sot_tag_columns AS exact_service_tag_column_count,
  @sot_total_columns AS sales_order_line_column_count,
  @sot_invalid_rows AS invalid_service_tag_row_count,
  @sot_failures AS postcheck_failure_count;

SET @sot_finish_sql := IF(
  @sot_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_SALES_ORDER_SERVICE_TAGS_POSTCHECK_FAILED'
);
PREPARE sot_finish_stmt FROM @sot_finish_sql;
EXECUTE sot_finish_stmt;
DEALLOCATE PREPARE sot_finish_stmt;
