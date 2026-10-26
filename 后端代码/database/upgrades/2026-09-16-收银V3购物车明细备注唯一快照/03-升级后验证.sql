-- upgrade_key: 20260916-003-cashier-v3-cart-line-detail-remark-snapshot
SET @db := DATABASE();
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND COLUMN_NAME = 'detail_remark_snapshot'
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_entitlement_service_fact'
  )
ORDER BY TABLE_NAME;
SELECT CASE WHEN COUNT(*) = 3 THEN 'POSTCHECK_OK' ELSE 'POSTCHECK_FAILURE' END AS postcheck_result
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND COLUMN_NAME = 'detail_remark_snapshot'
  AND TABLE_NAME IN (
    'eb_cashier_v3_checkout_line_draft',
    'eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_entitlement_service_fact'
  );
SELECT CASE WHEN COUNT(*) = 1 THEN 'UPGRADE_LOG_OK' ELSE 'UPGRADE_LOG_FAILURE' END AS upgrade_log_result
FROM `eb_database_upgrade_log`
WHERE `upgrade_key` = '20260916-003-cashier-v3-cart-line-detail-remark-snapshot';
