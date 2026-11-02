-- 一笔正式收款可多次更正方式；每次保留更正前后值及原始收款指纹，金额不参与更正。
-- 原收款、payment_collected 与销售分摊事实保持原样；本表只追加审计轨迹。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_payment_method_correction` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `collection_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_collection_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `original_method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_name_snapshot` varchar(32) NOT NULL,
  `after_method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `after_name_snapshot` varchar(32) NOT NULL,
  `amount_cents` bigint NOT NULL,
  `reason` varchar(255) NOT NULL,
  `operator_id` bigint unsigned NOT NULL,
  `operator_name_snapshot` varchar(128) NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `occurred_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation` (`operation_id`),
  UNIQUE KEY `uk_tenant_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_collection_history` (`tenant_id`,`collection_id`,`id`),
  KEY `idx_order_history` (`tenant_id`,`sales_order_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='销售订单正式收款方式更正审计';

-- 当前有效方式是可重建投影，不替代原始收款事实；报表、列表和导出统一读取它。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_payment_method_current` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `collection_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `payment_fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_method` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `current_name_snapshot` varchar(32) NOT NULL,
  `amount_cents` bigint NOT NULL,
  `last_operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `updated_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_collection` (`tenant_id`,`collection_id`),
  UNIQUE KEY `uk_tenant_payment_fact` (`tenant_id`,`payment_fact_id`),
  KEY `idx_order` (`tenant_id`,`sales_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='销售收款方式当前有效投影，可从更正审计重建';
