-- upgrade_key: 20260822-001-cashier-v3-recharge-gift-reversal-audit
SET NAMES utf8mb4;
SET @rgr_db := DATABASE();

SELECT COUNT(*) INTO @rgr_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rgr_db AND TABLE_NAME='eb_cashier_v3_recharge_gift_reversal' AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @rgr_unique_keys
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@rgr_db AND TABLE_NAME='eb_cashier_v3_recharge_gift_reversal'
  AND INDEX_NAME IN ('uk_reversal_id','uk_operation_item') AND NON_UNIQUE=0;

SELECT COUNT(*) INTO @rgr_invalid
FROM eb_cashier_v3_recharge_gift_reversal
WHERE reversal_id='' OR operation_id='' OR tenant_id='' OR recharge_id=0
   OR gift_id='' OR gift_item_id='' OR original_gift_fact_id='' OR reversal_gift_fact_id=''
   OR command_idempotency_key='' OR status NOT IN ('revoked');

SELECT IF(@rgr_tables=1 AND @rgr_unique_keys=2 AND @rgr_invalid=0,
  'POSTCHECK_OK', 'STOP_RECHARGE_GIFT_REVERSAL_AUDIT_POSTCHECK_FAILED') AS postcheck_result;
