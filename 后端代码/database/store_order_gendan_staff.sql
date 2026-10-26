-- 跟单人员（收银台单选员工）
ALTER TABLE `eb_store_order`
  ADD COLUMN `gendan_staff_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '跟单员工ID' AFTER `is_gendan`;

ALTER TABLE `eb_user_recharge`
  ADD COLUMN `is_gendan` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否跟单 0否 1是' AFTER `is_budan`,
  ADD COLUMN `gendan_staff_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '跟单员工ID' AFTER `is_gendan`;
