-- upgrade_key: 20260913-001-cashier-v3-multi-card-upgrade-snapshot-v1
SET NAMES utf8mb4;
SET @mcu_db := DATABASE();
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@mcu_db AND TABLE_NAME IN (
  'eb_cashier_v3_checkout_request','eb_cashier_v3_checkout_line_draft',
  'eb_cashier_v3_card_state','eb_user_card_holder','eb_store_order','eb_store_order_cart_info'
)
ORDER BY TABLE_NAME;
