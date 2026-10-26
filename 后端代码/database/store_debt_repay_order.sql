-- 补交订单标识
ALTER TABLE `eb_store_order`
  ADD COLUMN `is_debt_repay` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否补交订单' AFTER `repaid_debt_amount`,
  ADD COLUMN `debt_repay_origin_order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '补交原订单ID' AFTER `is_debt_repay`,
  ADD COLUMN `debt_repay_item_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '补交欠款明细ID' AFTER `debt_repay_origin_order_id`;

ALTER TABLE `eb_store_debt_repay`
  ADD COLUMN `repay_order_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '补交生成订单ID' AFTER `order_sn`;
