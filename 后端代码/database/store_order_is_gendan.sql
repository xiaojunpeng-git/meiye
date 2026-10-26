-- 订单是否跟单（收银台结账可选）
ALTER TABLE `eb_store_order`
  ADD COLUMN `is_gendan` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否跟单 0否 1是' AFTER `is_budan`;
