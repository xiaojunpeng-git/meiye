-- 门店目标员工分配（执行一次）
CREATE TABLE IF NOT EXISTS `eb_store_target_allocate` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `target_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '目标ID',
  `store_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '门店ID',
  `allocate_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1核心指标 2商品',
  `ref_key` varchar(64) NOT NULL DEFAULT '' COMMENT 'metric_key 或 product_{id}_{metric_key}',
  `staff_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '员工ID',
  `staff_name` varchar(64) NOT NULL DEFAULT '' COMMENT '员工姓名',
  `allocate_value` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '分配值',
  `add_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_target_ref` (`target_id`,`ref_key`,`allocate_type`),
  KEY `idx_store_staff` (`store_id`,`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店目标员工分配';
