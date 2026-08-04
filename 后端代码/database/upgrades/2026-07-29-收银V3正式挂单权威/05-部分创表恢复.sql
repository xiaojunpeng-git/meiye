-- upgrade_key: 20260729-017-cashier-v3-hang-order-authority-v1
-- Read-only partial-create audit. Does not drop or mutate data.
SET NAMES utf8mb4;
SET @ho_db := DATABASE();
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ho_db
  AND TABLE_NAME IN ('eb_cashier_v3_hang_order','eb_cashier_v3_hang_order_line')
ORDER BY TABLE_NAME;

SELECT COUNT(*) AS existing_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ho_db
  AND TABLE_NAME IN ('eb_cashier_v3_hang_order','eb_cashier_v3_hang_order_line');
