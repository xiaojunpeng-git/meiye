-- upgrade_key: 20260811-001-cashier-v3-line-coupon-snapshot
SET NAMES utf8mb4;
SET @clc_db := DATABASE();
SET @clc_failures := 0;

SELECT COUNT(*) INTO @clc_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@clc_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_workspace_line','eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order_line','eb_cashier_v3_sale_fact'
  )
  AND COLUMN_NAME IN ('coupon_user_id','coupon_name_snapshot','coupon_discount_cents');

SELECT COUNT(*) INTO @clc_invalid_workspace
FROM eb_cashier_v3_workspace_line
WHERE (coupon_user_id=0 AND (coupon_name_snapshot<>'' OR coupon_discount_cents<>0))
   OR (coupon_user_id>0 AND (coupon_name_snapshot='' OR coupon_discount_cents<0));

SELECT COUNT(*) INTO @clc_invalid_checkout
FROM eb_cashier_v3_checkout_line_draft
WHERE (coupon_user_id=0 AND (coupon_name_snapshot<>'' OR coupon_discount_cents<>0))
   OR (coupon_user_id>0 AND (coupon_name_snapshot='' OR coupon_discount_cents<0));

SET @clc_failures := @clc_failures
  + IF(@clc_columns=12,0,1)
  + IF(@clc_invalid_workspace=0,0,1)
  + IF(@clc_invalid_checkout=0,0,1);

SELECT @clc_columns AS expected_column_count,
       @clc_invalid_workspace AS invalid_workspace_coupon_rows,
       @clc_invalid_checkout AS invalid_checkout_coupon_rows,
       @clc_failures AS postcheck_failure_count;

SET @clc_finish_sql := IF(@clc_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_LINE_COUPON_POSTCHECK_FAILED');
PREPARE clc_finish_stmt FROM @clc_finish_sql;
EXECUTE clc_finish_stmt;
DEALLOCATE PREPARE clc_finish_stmt;
