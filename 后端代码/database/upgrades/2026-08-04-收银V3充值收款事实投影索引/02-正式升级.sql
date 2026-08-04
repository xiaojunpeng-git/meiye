-- upgrade_key: 20260804-005-cashier-v3-recharge-payment-fact-read-index
-- MySQL 5.6 compatible. Index-only change; no existing rows are modified.
SET NAMES utf8mb4;

ALTER TABLE `eb_cashier_v3_payment_fact`
  ADD KEY `idx_recharge_projection` (`source_document_type`,`status`,`store_id`,`order_no_snapshot`);

SELECT 'APPLY_OK' AS apply_result;
