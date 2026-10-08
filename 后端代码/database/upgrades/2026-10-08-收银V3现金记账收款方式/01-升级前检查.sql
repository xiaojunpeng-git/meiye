-- upgrade_key: 20261008-001-cashier-v3-cash-payment-method
-- MySQL 5.6 compatible. Read-only precheck for one independent customer database.
SET NAMES utf8mb4;

SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name = 'eb_cashier_v3_payment_method_config';

SELECT code, default_name, display_name, status, sort, version
FROM eb_cashier_v3_payment_method_config
WHERE code IN ('cash','unionpay','wechat','alipay','dianping_voucher','douyin_voucher','partner_collection','other_collection','old_card_entry')
ORDER BY sort, id;

SELECT COUNT(*) AS duplicate_cash_method_count
FROM eb_cashier_v3_payment_method_config
WHERE code = 'cash';
