-- 每次取消保留独立事实；恢复后可再次取消同一欠款，不覆盖旧审计。
ALTER TABLE `eb_cashier_v3_debt_cancellation`
  DROP INDEX `uq_debt`,
  ADD KEY `idx_debt_latest` (`debt_id`, `id`);

-- 一条恢复事实只逆转对应的一次取消，旧取消与恢复均不可改写。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_debt_restoration` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `restoration_id` varchar(48) NOT NULL,
  `cancellation_id` varchar(48) NOT NULL,
  `debt_id` int unsigned NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `store_id` int unsigned NOT NULL,
  `member_id` int unsigned NOT NULL,
  `restored_debt_cents` bigint NOT NULL,
  `operator_id` int unsigned NOT NULL,
  `operator_name_snapshot` varchar(100) NOT NULL,
  `store_name_snapshot` varchar(100) NOT NULL,
  `command_idempotency_key` varchar(128) NOT NULL,
  `business_event_no` varchar(64) NOT NULL,
  `business_date` date NOT NULL,
  `occurred_at` int unsigned NOT NULL,
  `settled_at` int unsigned NOT NULL,
  `recorded_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_restoration` (`restoration_id`),
  UNIQUE KEY `uq_cancellation` (`cancellation_id`),
  UNIQUE KEY `uq_command` (`tenant_id`, `command_idempotency_key`),
  KEY `idx_debt_latest` (`debt_id`, `id`),
  KEY `idx_store_date` (`store_id`, `business_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='独立恢复欠款的不可变反向事实';
