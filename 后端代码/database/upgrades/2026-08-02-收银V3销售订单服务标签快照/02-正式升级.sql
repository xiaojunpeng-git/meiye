-- upgrade_key: 20260802-001-cashier-v3-sales-order-service-tags-v1
-- MySQL 5.6 compatible. No historical order data is rewritten.
SET NAMES utf8mb4;

ALTER TABLE `eb_cashier_v3_sales_order_line`
  ADD COLUMN `service_object` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'locked project service object: self or friend' AFTER `category_name_snapshot`,
  ADD COLUMN `is_experience` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT 'locked project experience tag: 0 normal, 1 experience' AFTER `service_object`;

SELECT 'APPLY_OK' AS apply_result;
