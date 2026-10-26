-- upgrade_key: 20260808-008-cashier-v3-recharge-business-date
-- Add the audit reason paired with an editable historical recharge date.
SET NAMES utf8mb4;

ALTER TABLE `eb_cashier_v3_recharge_checkout_request`
  ADD COLUMN `business_date_reason` varchar(200) NOT NULL DEFAULT '' AFTER `business_date`;

SELECT 'APPLY_OK' AS apply_result;
