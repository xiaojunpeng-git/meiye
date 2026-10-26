-- upgrade_key: 20260804-001-cashier-v3-repeat-payment-method-drafts
-- MySQL 5.6 compatible. Schema-only change; no existing rows are modified.
SET NAMES utf8mb4;

ALTER TABLE `eb_cashier_v3_checkout_payment_draft`
  DROP INDEX `uk_request_method`;

SELECT 'APPLY_OK' AS apply_result;
