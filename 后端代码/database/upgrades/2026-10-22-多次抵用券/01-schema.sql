-- Coupon allowance is not member cash: claims keep their original face value.
-- Accounts are initialized from the claim only on the first committed use.
CREATE TABLE IF NOT EXISTS `eb_coupon_allowance_account` (
  `coupon_user_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `total_cents` bigint NOT NULL,
  `remaining_cents` bigint NOT NULL,
  `version` bigint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`coupon_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Reusable non-cash coupon allowance';

-- Reversals append a positive movement referencing exactly one debit. Never
-- overwrite the original redemption or rebuild balances from browser drafts.
CREATE TABLE IF NOT EXISTS `eb_coupon_allowance_movement` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `coupon_user_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `order_id` varchar(64) NOT NULL,
  `order_line_id` varchar(64) NOT NULL,
  `operation_id` varchar(64) NOT NULL DEFAULT '',
  `event_key` char(64) NOT NULL,
  `delta_cents` bigint NOT NULL,
  `before_cents` bigint NOT NULL,
  `after_cents` bigint NOT NULL,
  `reversal_of` bigint unsigned DEFAULT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_event` (`coupon_user_id`,`event_key`),
  UNIQUE KEY `uq_reversal` (`reversal_of`),
  KEY `idx_order` (`tenant_id`,`order_id`,`order_line_id`),
  KEY `idx_coupon` (`coupon_user_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Append-only reusable coupon redemption and void facts';
