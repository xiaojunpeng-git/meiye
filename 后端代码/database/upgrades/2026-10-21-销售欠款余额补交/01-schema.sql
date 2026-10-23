-- Immutable tender allocation; void keeps these rows and changes repayment lifecycle only.
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_debt_repayment_item_settlement` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(64) NOT NULL,
  `repayment_id` varchar(64) NOT NULL,
  `debt_item_id` bigint unsigned NOT NULL,
  `order_line_id` varchar(64) NOT NULL DEFAULT '',
  `legacy_order_id` bigint unsigned NOT NULL DEFAULT 0,
  `cart_info_id` bigint unsigned NOT NULL DEFAULT 0,
  `amount_cents` bigint NOT NULL,
  `balance_amount_cents` bigint NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_repayment_item` (`tenant_id`,`repayment_id`,`debt_item_id`),
  KEY `idx_line` (`tenant_id`,`order_line_id`),
  KEY `idx_legacy` (`tenant_id`,`legacy_order_id`,`cart_info_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Sales debt immutable cash/balance item settlement';
