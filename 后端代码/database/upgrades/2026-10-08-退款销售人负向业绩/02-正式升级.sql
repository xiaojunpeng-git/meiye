-- 退款销售人是退款自身的可修订归属，不覆盖原销售单人员事实。
ALTER TABLE `eb_cashier_v3_refund_adjustment`
  ADD COLUMN `salespeople_snapshot_json` mediumtext NULL COMMENT '当前退款销售人分配快照',
  ADD COLUMN `salespeople_version` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '退款销售人乐观锁版本',
  ADD COLUMN `source_sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '原V3销售单ID',
  ADD COLUMN `source_sales_line_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT '原V3销售明细ID';

-- 每次人工改派独立审计和幂等；旧负向事实以 reversal 抵销，原销售事实永不修改。
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_refund_salesperson_revision` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `revision_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `immutable_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `old_snapshot_json` mediumtext NOT NULL,
  `new_snapshot_json` mediumtext NOT NULL,
  `reason` varchar(255) NOT NULL,
  `business_event_no` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_revision` (`revision_id`),
  UNIQUE KEY `uk_command` (`tenant_id`,`command_idempotency_key`),
  KEY `idx_refund` (`tenant_id`,`operation_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='退款销售人追加式调整审计';
