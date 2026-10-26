-- upgrade_key: 20260805-004-cashier-v3-order-lifecycle-authority
-- The lifecycle ledger depends on V3 sales orders, facts and workspaces.
SET NAMES utf8mb4;
SET @lifecycle_db := DATABASE();

SELECT COUNT(*) INTO @lifecycle_dependencies
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@lifecycle_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_sales_order',
    'eb_cashier_v3_sales_order_line',
    'eb_cashier_v3_sale_fact',
    'eb_cashier_v3_payment_fact',
    'eb_cashier_v3_balance_fact',
    'eb_cashier_v3_performance_fact',
    'eb_cashier_v3_workspace_draft',
    'eb_cashier_v3_workspace_line',
    'eb_cashier_v3_debt_authority',
    'eb_store_debt',
    'eb_cashier_v3_card_purchase_receipt',
    'eb_user_card_holder',
    'eb_store_order',
    'eb_store_order_cart_info',
    'eb_cashier_v3_card_rule_state',
    'eb_cashier_v3_card_rule_component',
    'eb_cashier_v3_entitlement_writeoff_fact',
    'eb_cashier_v3_service_order_line',
    'eb_store_reservation_order',
    'eb_cashier_v3_card_operation',
    'eb_cashier_v3_card_state'
  )
  AND ENGINE='InnoDB';

SELECT IF(@lifecycle_dependencies=21,
  'PRECHECK_OK', 'STOP_CASHIER_V3_ORDER_LIFECYCLE_PRECHECK_FAILED') AS precheck_result;
