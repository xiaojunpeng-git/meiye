-- 第22轮：充值业务日归于主单；订单归属更正只记录字段前后值，不引入单据版本。
SET @correction_db := DATABASE();
SET @correction_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @correction_db AND TABLE_NAME = 'eb_user_recharge' AND COLUMN_NAME = 'business_date') = 0,
  'ALTER TABLE `eb_user_recharge` ADD COLUMN `business_date` date NULL AFTER `pay_time`, ADD KEY `idx_recharge_business_date_store` (`store_id`, `business_date`, `id`)',
  'SELECT ''recharge business_date exists'''
);
PREPARE correction_statement FROM @correction_sql; EXECUTE correction_statement; DEALLOCATE PREPARE correction_statement;

-- 正向充值收款事实优先；纯赠金及历史充值没有收款事实时才使用结账请求或原列表日期。
UPDATE eb_user_recharge r
JOIN (
  SELECT store_id, order_no_snapshot, MAX(business_date) AS business_date
  FROM eb_cashier_v3_payment_fact
  WHERE source_document_type = 'recharge' AND fact_direction = 'forward' AND status = 'effective'
  GROUP BY store_id, order_no_snapshot
) pf ON pf.store_id = r.store_id AND pf.order_no_snapshot = r.order_id
SET r.business_date = pf.business_date
WHERE r.business_date IS NULL;
UPDATE eb_user_recharge r
JOIN eb_cashier_v3_recharge_checkout_request q ON q.recharge_id = r.id AND q.request_status = 'completed'
SET r.business_date = q.business_date
WHERE r.business_date IS NULL;
UPDATE eb_user_recharge r
-- PHP 业务日固定 Asia/Shanghai；不能直接用 MySQL 容器本地时区解释 Unix 秒。
SET r.business_date = DATE(FROM_UNIXTIME(COALESCE(NULLIF(r.pay_time, 0), r.add_time)
  + 28800 - TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())))
WHERE r.business_date IS NULL;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_order_attribution_change` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` varchar(64) NOT NULL,
  `command_idempotency_key` varchar(128) NOT NULL,
  `request_fingerprint` char(64) NOT NULL,
  `tenant_id` varchar(32) NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `root_record_type` varchar(32) NOT NULL,
  `root_record_id` varchar(64) NOT NULL,
  `record_type` varchar(32) NOT NULL,
  `record_id` varchar(64) NOT NULL,
  `record_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `field_key` varchar(32) NOT NULL,
  `old_value` varchar(191) NOT NULL DEFAULT '',
  `new_value` varchar(191) NOT NULL DEFAULT '',
  `operator_id` bigint(20) unsigned NOT NULL,
  `operator_name_snapshot` varchar(128) NOT NULL DEFAULT '',
  `occurred_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_operation_record_field` (`tenant_id`, `operation_id`, `record_type`, `record_id`, `field_key`),
  KEY `idx_command` (`tenant_id`, `command_idempotency_key`),
  KEY `idx_detail` (`tenant_id`, `record_type`, `record_id`, `id`),
  KEY `idx_root` (`tenant_id`, `root_record_type`, `root_record_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单业务日期与来源更正记录';

-- 修复在 UTC MySQL 已执行旧回填时可能偏前一日的历史充值；保留已经人工更正的主单，
-- 同时不覆盖有正式 V3 收款事实或已完成结账请求所确定的业务日期。
UPDATE eb_user_recharge r
SET r.business_date = DATE(FROM_UNIXTIME(COALESCE(NULLIF(r.pay_time, 0), r.add_time)
  + 28800 - TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())))
WHERE r.business_date = DATE(FROM_UNIXTIME(COALESCE(NULLIF(r.pay_time, 0), r.add_time)))
  AND NOT EXISTS (SELECT 1 FROM eb_cashier_v3_order_attribution_change change_log
    WHERE change_log.record_type = 'recharge' AND change_log.record_id = CAST(r.id AS CHAR) AND change_log.field_key = 'business_date')
  AND NOT EXISTS (SELECT 1 FROM eb_cashier_v3_payment_fact pf
    WHERE pf.store_id = r.store_id AND pf.order_no_snapshot = r.order_id
      AND pf.source_document_type = 'recharge' AND pf.fact_direction = 'forward' AND pf.status = 'effective')
  AND NOT EXISTS (SELECT 1 FROM eb_cashier_v3_recharge_checkout_request request_record
    WHERE request_record.recharge_id = r.id AND request_record.request_status = 'completed');
