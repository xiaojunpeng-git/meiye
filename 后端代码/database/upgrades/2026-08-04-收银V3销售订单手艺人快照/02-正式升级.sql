-- upgrade_key: 20260804-002-cashier-v3-sales-order-craftsmen-snapshot-v1
-- MySQL 5.6 compatible. Schema-only change; no existing rows are modified.
-- MySQL 5.6 prohibits defaults on MEDIUMTEXT. New V3 writers explicitly persist [].
SET NAMES utf8mb4;

ALTER TABLE `eb_cashier_v3_checkout_line_draft`
  ADD COLUMN `craftsmen_snapshot_json` MEDIUMTEXT NOT NULL
  COMMENT 'immutable server-locked project craftsmen snapshot JSON; [] for non-project lines'
  AFTER `is_experience`;

ALTER TABLE `eb_cashier_v3_sales_order_line`
  ADD COLUMN `craftsmen_snapshot_json` MEDIUMTEXT NOT NULL
  COMMENT 'immutable checkout project craftsmen snapshot JSON; [] for non-project lines'
  AFTER `is_experience`;

SELECT 'APPLY_OK' AS apply_result;
