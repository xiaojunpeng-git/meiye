-- upgrade_key: 20260901-001-fuyou-payment-attempt
SET NAMES utf8mb4;

SELECT DATABASE() AS current_database;
SELECT COUNT(*) AS existing_table_count
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('eb_fuyou_payment_attempt', 'eb_wechat_accesstoken');
