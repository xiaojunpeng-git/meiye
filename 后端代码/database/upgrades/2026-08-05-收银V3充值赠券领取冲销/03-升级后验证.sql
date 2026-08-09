-- upgrade_key: 20260805-005-cashier-v3-recharge-gift-coupon-claim-reversal
SET NAMES utf8mb4;
SET @rgcm_db := DATABASE();

SELECT COUNT(*) INTO @rgcm_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@rgcm_db
  AND TABLE_NAME='eb_cashier_v3_recharge_gift_coupon_issue_mapping'
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @rgcm_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@rgcm_db
  AND TABLE_NAME='eb_cashier_v3_recharge_gift_coupon_issue_mapping'
  AND COLUMN_NAME IN (
    'mapping_id','tenant_id','store_id','member_id','recharge_id','gift_id','gift_item_id','item_sequence',
    'coupon_issue_id','coupon_user_id','coupon_issue_user_id','status','void_operation_id','voided_at'
  );

SELECT COUNT(*) INTO @rgcm_issue_user_identity
FROM information_schema.COLUMNS c
JOIN information_schema.STATISTICS s
  ON s.TABLE_SCHEMA=c.TABLE_SCHEMA AND s.TABLE_NAME=c.TABLE_NAME AND s.COLUMN_NAME=c.COLUMN_NAME
WHERE c.TABLE_SCHEMA=@rgcm_db AND c.TABLE_NAME='eb_store_coupon_issue_user' AND c.COLUMN_NAME='id'
  AND c.EXTRA='auto_increment' AND s.INDEX_NAME='PRIMARY' AND s.NON_UNIQUE=0;

SELECT COUNT(DISTINCT INDEX_NAME) INTO @rgcm_unique
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@rgcm_db
  AND TABLE_NAME='eb_cashier_v3_recharge_gift_coupon_issue_mapping'
  AND INDEX_NAME IN ('uk_mapping_id','uk_coupon_user','uk_coupon_issue_user','uk_gift_item_sequence')
  AND NON_UNIQUE=0;

SELECT COUNT(*) INTO @rgcm_invalid
FROM eb_cashier_v3_recharge_gift_coupon_issue_mapping
WHERE mapping_id='' OR tenant_id='' OR member_id=0 OR recharge_id=0 OR gift_id='' OR gift_item_id=''
   OR item_sequence=0 OR coupon_issue_id=0 OR coupon_user_id=0 OR coupon_issue_user_id=0
   OR status NOT IN ('issued','voided')
   OR (status='issued' AND (void_operation_id<>'' OR voided_at<>0))
   OR (status='voided' AND (void_operation_id='' OR voided_at=0));

SELECT IF(@rgcm_table=1 AND @rgcm_columns=14 AND @rgcm_unique=4 AND @rgcm_issue_user_identity=1 AND @rgcm_invalid=0,
  'POSTCHECK_OK', 'STOP_RECHARGE_GIFT_COUPON_MAPPING_POSTCHECK_FAILED') AS postcheck_result;
