-- upgrade_key: 20261008-001-cashier-v3-cash-payment-method
-- MySQL 5.6 compatible. Adds the product-approved cash bookkeeping collection method.
SET NAMES utf8mb4;

INSERT INTO eb_cashier_v3_payment_method_config
  (code, default_name, display_name, status, sort, version, created_at, updated_at)
VALUES
  ('cash', '现金', '现金', 1, 5, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  default_name = VALUES(default_name);

SELECT 'APPLY_OK' AS apply_result;
