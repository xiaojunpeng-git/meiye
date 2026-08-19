SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'eb_cashier_v3_entitlement_service_fact',
    'eb_cashier_v3_entitlement_writeoff_fact',
    'eb_cashier_v3_performance_fact',
    'eb_cashier_v3_entitlement_resource_version',
    'eb_user_card_holder',
    'eb_store_order_cart_info'
  )
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME IN ('id','service_fact_id','tenant_id','store_id','checkout_request_id','source_line_id','quantity','service_status'))
    OR (TABLE_NAME='eb_cashier_v3_entitlement_writeoff_fact' AND COLUMN_NAME IN ('writeoff_id','checkout_request_id','source_line_id','holder_id','source_detail_id','origin_order_id'))
    OR (TABLE_NAME='eb_cashier_v3_performance_fact' AND COLUMN_NAME IN ('fact_id','checkout_request_id','source_line_id','performance_type','amount_cents','labor_fee_amount_cents','fact_direction','reversal_of'))
  )
ORDER BY TABLE_NAME, COLUMN_NAME;
