-- upgrade_key: 20260803-004-cashier-v3-checkout-draft-service-tags-v1
-- MySQL 5.6 compatible. No existing business data is rewritten.
SET NAMES utf8mb4;

ALTER TABLE `eb_cashier_v3_checkout_line_draft`
  ADD COLUMN `service_object` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'locked project service object: self or friend' AFTER `project_version`,
  ADD COLUMN `is_experience` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT 'locked project experience tag: 0 normal, 1 experience' AFTER `service_object`;

SELECT 'APPLY_OK' AS apply_result;
