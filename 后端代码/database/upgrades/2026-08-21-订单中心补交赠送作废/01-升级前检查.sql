-- upgrade_key: 20260821-001-cashier-v3-order-center-void
-- Read-only precheck; run before 02-正式升级.sql.
SET NAMES utf8mb4;
SET @ocv_db := DATABASE();
SELECT @ocv_db AS db_name, VERSION() AS mysql_version;
SELECT COUNT(*) AS existing_target_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ocv_db AND TABLE_NAME='eb_cashier_v3_order_center_void_operation';
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ocv_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_recharge_debt_repayment',
    'eb_cashier_v3_debt_repayment',
    'eb_cashier_v3_gift_fact',
    'eb_cashier_v3_direct_gift_authority',
    'eb_cashier_v3_direct_gift_item',
    'eb_cashier_v3_business_event'
  );
SELECT 'PRECHECK_OK' AS precheck_result;
