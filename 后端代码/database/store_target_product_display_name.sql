-- 品项指标目标名称（与目标设置页「目标名称」一致）
ALTER TABLE `eb_store_target_product`
  ADD COLUMN `display_name` varchar(128) NOT NULL DEFAULT '' COMMENT '目标名称' AFTER `product_name`;
