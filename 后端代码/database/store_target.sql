-- 门店目标管理（执行一次）
CREATE TABLE IF NOT EXISTS `eb_store_target` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL DEFAULT '' COMMENT '目标名称',
  `store_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建门店ID',
  `object_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '对象类型 1门店 2区域 3集团',
  `object_name` varchar(128) NOT NULL DEFAULT '' COMMENT '对象名称',
  `time_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '时间类型 1月 2年 3周期',
  `year` int(4) NOT NULL DEFAULT '0' COMMENT '年份',
  `month` tinyint(2) NOT NULL DEFAULT '0' COMMENT '月份',
  `period_start` int(11) NOT NULL DEFAULT '0' COMMENT '周期开始时间戳',
  `period_end` int(11) NOT NULL DEFAULT '0' COMMENT '周期结束时间戳',
  `metric_count` int(11) NOT NULL DEFAULT '0' COMMENT '指标数量',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1启用 0禁用',
  `is_del` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否删除',
  `add_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_store_year` (`store_id`,`year`,`is_del`),
  KEY `idx_object` (`object_type`,`is_del`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店目标';

CREATE TABLE IF NOT EXISTS `eb_store_target_metric` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `target_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '目标ID',
  `metric_key` varchar(32) NOT NULL DEFAULT '' COMMENT '指标键',
  `metric_name` varchar(64) NOT NULL DEFAULT '' COMMENT '指标名称',
  `target_value` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '目标值',
  `unit` varchar(16) NOT NULL DEFAULT '' COMMENT '单位',
  `sort` int(11) NOT NULL DEFAULT '0',
  `metric_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1核心指标 2产品指标',
  PRIMARY KEY (`id`),
  KEY `idx_target_id` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店目标核心指标';

CREATE TABLE IF NOT EXISTS `eb_store_target_product` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `target_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '目标ID',
  `product_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '商品ID',
  `product_name` varchar(255) NOT NULL DEFAULT '' COMMENT '商品名称',
  `display_name` varchar(128) NOT NULL DEFAULT '' COMMENT '目标名称',
  `metric_key` varchar(32) NOT NULL DEFAULT '' COMMENT '指标键 revenue/count/consume/service',
  `metric_name` varchar(64) NOT NULL DEFAULT '' COMMENT '指标名称',
  `target_value` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '目标值',
  `unit` varchar(16) NOT NULL DEFAULT '' COMMENT '单位',
  `sort` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_target_product` (`target_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店目标产品指标';
