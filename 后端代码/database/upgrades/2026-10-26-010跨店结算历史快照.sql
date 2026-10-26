-- 010 专用报表读模型。仅保存 007 固定只读快照的跨店结算必要字段；
-- 不写入旧订单、收银、余额或权益事实。配置批次 ID 前先完成导入与逐店校验。
CREATE TABLE IF NOT EXISTS `eb_basic_cross_store_legacy_batch` (
  `batch_id` varchar(64) NOT NULL COMMENT '固定快照批次身份',
  `source_instance` varchar(16) NOT NULL COMMENT '只读来源实例',
  `snapshot_sha256` char(64) NOT NULL COMMENT '原始 JSONL 文件摘要',
  `captured_at` datetime NOT NULL COMMENT '来源库一致读取开始时点',
  `range_start` date NOT NULL,
  `range_end` date NOT NULL,
  `order_count` int unsigned NOT NULL,
  `status` varchar(16) NOT NULL COMMENT 'loading 或 sealed；只读取 sealed',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='基础报表跨店历史固定批次';

CREATE TABLE IF NOT EXISTS `eb_basic_cross_store_legacy_order` (
  `batch_id` varchar(64) NOT NULL,
  `source_order_id` bigint unsigned NOT NULL COMMENT '007 原订单主键，仅作追溯身份',
  `store_id` int unsigned NOT NULL COMMENT '007 交易门店',
  `kua_store` int unsigned NOT NULL COMMENT '007 权益所属门店',
  `order_type` tinyint unsigned NOT NULL COMMENT '0 储值；2 次卡',
  `business_date` date NOT NULL COMMENT '来源 add_time 的上海本地日期',
  `source_add_time` int unsigned NOT NULL,
  `amount_cents` bigint NOT NULL COMMENT '报表金额，原始分精度',
  `source_row_sha256` char(64) NOT NULL COMMENT '固定来源字段的逐行摘要',
  PRIMARY KEY (`batch_id`,`source_order_id`),
  KEY `idx_batch_date` (`batch_id`,`business_date`),
  KEY `idx_batch_store` (`batch_id`,`store_id`,`kua_store`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='基础报表跨店历史订单投影';
