-- New orders opt in at creation. Historical orders are never retroactively issued rights.
CREATE TABLE IF NOT EXISTS `eb_combination_group_origin` (
 `group_id` INT UNSIGNED NOT NULL,
 `expires_at` INT UNSIGNED NOT NULL,
 `activity_name` VARCHAR(255) NOT NULL,
 PRIMARY KEY (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='开团名称及原到期时间，成团不得覆盖';

CREATE TABLE IF NOT EXISTS `eb_combination_force_completion` (
 `group_id` INT UNSIGNED NOT NULL,
 `operator_id` INT UNSIGNED NOT NULL DEFAULT 0,
 `virtual_count` INT UNSIGNED NOT NULL,
 `created_at` INT UNSIGNED NOT NULL,
 PRIMARY KEY (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人工或规则补齐虚拟名额成团审计';

CREATE TABLE IF NOT EXISTS `eb_combination_payment_origin` (
 `channel_order_no` VARCHAR(30) NOT NULL,
 `merchant_id` VARCHAR(32) NOT NULL,
 `settled_on` VARCHAR(8) NOT NULL DEFAULT '',
 PRIMARY KEY (`channel_order_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团支付原商户及渠道交易日期，不存密钥';

CREATE TABLE IF NOT EXISTS `eb_combination_order_fulfillment` (
 `order_id` INT UNSIGNED NOT NULL,
 `state` VARCHAR(16) NOT NULL DEFAULT 'pending',
 `created_at` INT UNSIGNED NOT NULL,
 `issued_at` INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团成团发放凭据，权益仍以原订单明细为准';

-- One current refund workflow per participant order; retries keep the same channel number
-- until a signed PAYERROR proves failure. No money or entitlement facts are copied here.
CREATE TABLE IF NOT EXISTS `eb_combination_order_refund` (
 `order_id` INT UNSIGNED NOT NULL,
 `refund_no` VARCHAR(30) NOT NULL,
 `attempt` INT UNSIGNED NOT NULL DEFAULT 1,
 `state` VARCHAR(24) NOT NULL,
 `channel_order_no` VARCHAR(30) NOT NULL DEFAULT '',
 `channel` VARCHAR(16) NOT NULL DEFAULT '',
 `amount_cents` BIGINT UNSIGNED NOT NULL,
 `merchant_id` VARCHAR(32) NOT NULL DEFAULT '',
 `channel_refund_id` VARCHAR(64) NOT NULL DEFAULT '',
 `fuyou_refund_trace_no` VARCHAR(64) NOT NULL DEFAULT '',
 `channel_transaction_id` VARCHAR(100) NOT NULL DEFAULT '',
 `settled_on` VARCHAR(8) NOT NULL DEFAULT '',
 `operator_id` INT UNSIGNED NOT NULL DEFAULT 0,
 `kick_group_id` INT UNSIGNED NOT NULL DEFAULT 0,
 `reason` VARCHAR(300) NOT NULL DEFAULT '',
 `lease_until` INT UNSIGNED NOT NULL DEFAULT 0,
 `created_at` INT UNSIGNED NOT NULL,
 `updated_at` INT UNSIGNED NOT NULL,
 PRIMARY KEY (`order_id`),
 UNIQUE KEY `uq_combination_refund_no` (`refund_no`),
 KEY `idx_combination_refund_recovery` (`state`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拼团整单退款执行及渠道确认凭据';
