-- 拼团在线支付尝试独立于富友尝试，固定原渠道、商户、金额与业务订单。
-- 已存在的富友支付与退款记录不回填、不改写；同一订单可在明确失败后有新的尝试。
CREATE TABLE IF NOT EXISTS `eb_suixingpay_product_payment_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id_key` INT UNSIGNED NOT NULL,
  `business_order_no` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `channel_order_no` VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `merchant_id` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `amount_cents` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'created',
  `transaction_id` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `response_code` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `created_at` INT UNSIGNED NOT NULL,
  `updated_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_suixing_product_channel_order` (`channel_order_no`),
  KEY `idx_suixing_product_order` (`order_id_key`, `id`),
  KEY `idx_suixing_product_status` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团随行付支付尝试，非正式收款事实';

-- 旧退款回执没有 provider 字段，默认富友；新随行付回执在认领事务中写入 provider。
-- MySQL 5.7 不支持 ADD COLUMN IF NOT EXISTS，因此按信息模式判定后执行。
SET @has_provider = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_combination_order_refund' AND COLUMN_NAME = 'provider'
);
SET @provider_sql = IF(@has_provider = 0,
  'ALTER TABLE eb_combination_order_refund ADD COLUMN provider VARCHAR(16) NOT NULL DEFAULT ''fuyou'' AFTER channel',
  'SELECT ''provider_exists'' AS result');
PREPARE provider_stmt FROM @provider_sql;
EXECUTE provider_stmt;
DEALLOCATE PREPARE provider_stmt;
