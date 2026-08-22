-- upgrade_key: 20260822-001-cashier-v3-recharge-gift-reversal-audit
SET NAMES utf8mb4;
SET @rgr_db := DATABASE();

SELECT COUNT(*) INTO @rgr_dependencies
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rgr_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_recharge_gift_authority',
    'eb_cashier_v3_recharge_gift_item',
    'eb_cashier_v3_gift_fact',
    'eb_store_coupon_user',
    'eb_store_coupon_issue'
  )
  AND ENGINE='InnoDB';

SELECT IF(@rgr_dependencies=5, 'PRECHECK_OK',
  'STOP_RECHARGE_GIFT_REVERSAL_AUDIT_DEPENDENCY_MISSING') AS precheck_result;
