-- upgrade_key: 20260805-008-cashier-v3-recharge-checkout
SET NAMES utf8mb4;
SELECT CASE WHEN COUNT(*) = 2 THEN 'POSTCHECK_OK' ELSE 'STOP_RECHARGE_CHECKOUT_TABLE_MISSING' END AS postcheck_result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('eb_cashier_v3_recharge_checkout_request','eb_cashier_v3_recharge_checkout_payment_draft');
