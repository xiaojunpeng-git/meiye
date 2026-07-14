-- 储值记录欠款字段
ALTER TABLE `eb_user_recharge`
  ADD COLUMN `debt_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '欠款金额' AFTER `give_price`,
  ADD COLUMN `repaid_debt_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '已还欠款' AFTER `debt_amount`;
