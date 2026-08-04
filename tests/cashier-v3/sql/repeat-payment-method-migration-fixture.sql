CREATE DATABASE `cashier_v3_repeat_payment_test`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `cashier_v3_repeat_payment_test`;

CREATE TABLE `eb_database_upgrade_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `upgrade_key` varchar(128) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_upgrade_key` (`upgrade_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `eb_cashier_v3_checkout_payment_draft` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_draft_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_authority_key` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payment_draft_id` (`payment_draft_id`),
  UNIQUE KEY `uk_request_method` (`request_id`,`payment_method`),
  UNIQUE KEY `uk_request_payment_authority` (`request_id`,`payment_authority_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
