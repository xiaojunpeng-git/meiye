CREATE TABLE IF NOT EXISTS `eb_member_commerce_settings` (
  `id` tinyint unsigned NOT NULL,
  `purchase_primary_id` int unsigned NOT NULL DEFAULT 0,
  `purchase_secondary_id` int unsigned NOT NULL DEFAULT 0,
  `recharge_primary_id` int unsigned NOT NULL DEFAULT 0,
  `recharge_secondary_id` int unsigned NOT NULL DEFAULT 0,
  `version` int unsigned NOT NULL DEFAULT 1,
  `updated_by` int unsigned NOT NULL DEFAULT 0,
  `updated_at` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_member_commerce_settings_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `command_key` varchar(100) NOT NULL,
  `admin_id` int unsigned NOT NULL,
  `input_hash` char(64) NOT NULL,
  `before_json` text NOT NULL,
  `after_json` text NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `command_key` (`command_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_member_commerce_order` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `public_id` varchar(48) NOT NULL,
  `member_id` int unsigned NOT NULL,
  `store_id` int unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `channel` varchar(16) NOT NULL,
  `idempotency_key` varchar(80) NOT NULL,
  `input_fingerprint` char(64) NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `amount_cents` bigint unsigned NOT NULL,
  `balance_cents` bigint unsigned NOT NULL DEFAULT 0,
  `external_cents` bigint unsigned NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL,
  `result_json` longtext NOT NULL,
  `sales_order_id` varchar(64) NOT NULL DEFAULT '',
  `document_no` varchar(32) NOT NULL DEFAULT '',
  `transaction_id` varchar(100) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_id` (`public_id`),
  UNIQUE KEY `member_command` (`member_id`,`idempotency_key`),
  KEY `member_orders` (`member_id`,`created_at`,`id`),
  KEY `store_status` (`store_id`,`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_member_commerce_payment_session` (
  `commerce_id` bigint unsigned NOT NULL,
  `response_json` longtext NOT NULL,
  `status` varchar(24) NOT NULL,
  `created_at` int unsigned NOT NULL,
  `updated_at` int unsigned NOT NULL,
  PRIMARY KEY (`commerce_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
