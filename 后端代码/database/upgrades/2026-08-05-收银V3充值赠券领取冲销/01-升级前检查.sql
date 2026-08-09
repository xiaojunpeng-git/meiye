-- upgrade_key: 20260805-005-cashier-v3-recharge-gift-coupon-claim-reversal
SET NAMES utf8mb4;
SET @rgcm_db := DATABASE();

SELECT COUNT(*) INTO @rgcm_dependencies
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rgcm_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_recharge_gift_authority',
    'eb_cashier_v3_recharge_gift_item',
    'eb_cashier_v3_recharge_gift_reversal',
    'eb_store_coupon_user',
    'eb_store_coupon_issue_user'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @rgcm_existing
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rgcm_db
  AND TABLE_NAME='eb_cashier_v3_recharge_gift_coupon_issue_mapping';

SELECT COUNT(*) INTO @rgcm_issue_user_primary
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@rgcm_db AND TABLE_NAME='eb_store_coupon_issue_user'
  AND INDEX_NAME='PRIMARY';

SELECT IF(@rgcm_dependencies=5,
  IF(@rgcm_existing IN (0,1) AND @rgcm_issue_user_primary IN (0,1), 'PRECHECK_OK', 'STOP_RECHARGE_GIFT_COUPON_MAPPING_STATE_INVALID'),
  'STOP_RECHARGE_GIFT_COUPON_MAPPING_DEPENDENCY_MISSING') AS precheck_result;
