-- upgrade_key: 20261008-001-cashier-v3-cash-payment-method
-- MySQL 5.6 compatible. Verifies the cash method exists without changing business facts.
SET NAMES utf8mb4;

SELECT id, code, default_name, display_name, status, sort, version
FROM eb_cashier_v3_payment_method_config
WHERE code = 'cash';

SELECT COUNT(*) AS cash_method_config_count
FROM eb_cashier_v3_payment_method_config
WHERE code = 'cash'
  AND default_name = '现金'
  AND display_name <> ''
  AND status IN (0, 1);

SELECT COUNT(*) AS enabled_bookkeeping_method_count
FROM eb_cashier_v3_payment_method_config
WHERE status = 1
  AND code <> 'old_card_entry';
