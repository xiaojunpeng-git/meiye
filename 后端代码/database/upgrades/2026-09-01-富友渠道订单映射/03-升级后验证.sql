-- upgrade_key: 20260901-001-fuyou-payment-attempt
SET NAMES utf8mb4;

SELECT COUNT(*) AS table_exists
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('eb_fuyou_payment_attempt', 'eb_wechat_accesstoken');

SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'eb_fuyou_payment_attempt'
ORDER BY ordinal_position;

SELECT index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_in_index
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'eb_fuyou_payment_attempt'
GROUP BY index_name, non_unique
ORDER BY index_name;

SELECT column_name, column_type, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'eb_wechat_accesstoken'
ORDER BY ordinal_position;
