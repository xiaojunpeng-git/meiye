-- upgrade_key: 20260811-001-cashier-v3-card-operation-entitlement-credit-v1
-- Read-only precheck. The settlement authority joins card-operation, checkout,
-- sales and source-entitlement records in one successful checkout transaction.
SET NAMES utf8mb4;
SET @coe_db := DATABASE();
SET @coe_failures := 0;

SELECT COUNT(*) INTO @coe_required_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@coe_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_card_operation','eb_cashier_v3_card_operation_line',
    'eb_cashier_v3_checkout_request','eb_cashier_v3_sales_order',
    'eb_user_card_holder','eb_store_order','eb_store_order_cart_info',
    'eb_database_upgrade_log'
  ) AND ENGINE='InnoDB';

SET @coe_dependency_count := 0;
SET @coe_dependency_sql := IF(
  @coe_required_tables=8,
  'SELECT COUNT(*) INTO @coe_dependency_count FROM eb_database_upgrade_log WHERE upgrade_key IN (''20260729-010-cashier-v3-checkout-resource-plan'',''20260730-021-cashier-v3-card-operation-authority-v1'')',
  'SET @coe_dependency_count := 0'
);
PREPARE coe_dependency_stmt FROM @coe_dependency_sql;
EXECUTE coe_dependency_stmt;
DEALLOCATE PREPARE coe_dependency_stmt;

SET @coe_failures := IF(@coe_required_tables=8,0,1)
  + IF(@coe_dependency_count=2,0,1);
SELECT @coe_db AS db_name,@coe_required_tables AS required_table_count,
  @coe_dependency_count AS registered_dependency_count,
  @coe_failures AS precheck_failure_count;

SET @coe_finish_sql := IF(@coe_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_CARD_OPERATION_ENTITLEMENT_CREDIT_PRECHECK_FAILED'
);
PREPARE coe_finish_stmt FROM @coe_finish_sql;
EXECUTE coe_finish_stmt;
DEALLOCATE PREPARE coe_finish_stmt;
