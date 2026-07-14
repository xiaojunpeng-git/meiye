-- 订单欠款字段
ALTER TABLE `eb_store_order`
  ADD COLUMN `debt_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '订单欠款总额' AFTER `cash_pay_price`,
  ADD COLUMN `repaid_debt_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '订单已还欠款' AFTER `debt_amount`;

ALTER TABLE `eb_store_order_cart_info`
  ADD COLUMN `debt_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '明细欠款金额' AFTER `cash_pay_amount`,
  ADD COLUMN `repaid_debt_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '明细已还欠款' AFTER `debt_amount`;

-- 欠款主记录（一单一条，含多明细欠款汇总）
CREATE TABLE IF NOT EXISTS `eb_store_debt` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `debt_no` varchar(32) NOT NULL DEFAULT '' COMMENT '欠款单号',
  `order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '原订单ID',
  `order_sn` varchar(32) NOT NULL DEFAULT '' COMMENT '原交易单号',
  `uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `store_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '欠款门店ID',
  `staff_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '收银员/店员ID',
  `total_debt` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '欠款总额',
  `repaid_debt` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '已还金额',
  `status` tinyint(3) NOT NULL DEFAULT 0 COMMENT '0待还款 1已结清 2已关闭 3已作废',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `add_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '欠款时间',
  `update_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_debt_no` (`debt_no`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_uid_status` (`uid`, `status`),
  KEY `idx_store_id` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='欠款记录';

-- 欠款明细（按订单商品行）
CREATE TABLE IF NOT EXISTS `eb_store_debt_item` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `debt_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '欠款主记录ID',
  `order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '原订单ID',
  `cart_info_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '订单明细ID',
  `product_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '商品ID',
  `product_type` tinyint(3) NOT NULL DEFAULT 0 COMMENT '商品类型',
  `product_name` varchar(255) NOT NULL DEFAULT '' COMMENT '商品名称',
  `cart_num` int(11) NOT NULL DEFAULT 1 COMMENT '数量',
  `debt_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '欠款金额',
  `repaid_debt` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '已还金额',
  `add_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '添加时间',
  `update_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_debt_id` (`debt_id`),
  KEY `idx_order_cart` (`order_id`, `cart_info_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='欠款明细';

-- 还款记录
CREATE TABLE IF NOT EXISTS `eb_store_debt_repay` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `repay_no` varchar(32) NOT NULL DEFAULT '' COMMENT '还款单号',
  `debt_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '欠款主记录ID',
  `debt_item_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '欠款明细ID，0表示按主单还款',
  `order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '原订单ID',
  `order_sn` varchar(32) NOT NULL DEFAULT '' COMMENT '原交易单号',
  `uid` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `repay_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '还款金额',
  `pay_type` varchar(32) NOT NULL DEFAULT '' COMMENT '支付方式',
  `pay_store_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '还款门店',
  `debt_store_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '欠款门店',
  `staff_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '收银员ID',
  `combination_info` text COMMENT '组合支付明细JSON',
  `add_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '还款时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_repay_no` (`repay_no`),
  KEY `idx_debt_id` (`debt_id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='欠款还款记录';

-- 后台菜单（可选）：在「门店」菜单下新增「欠款管理」，路径 /admin/store/debt/index，权限标识可与门店订单共用 admin-store-store_order
