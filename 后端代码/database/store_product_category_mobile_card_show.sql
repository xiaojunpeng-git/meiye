-- 商品分类：手机端卡包是否展示该分类
ALTER TABLE `store_product_category`
    ADD COLUMN `mobile_card_show` tinyint(1) NOT NULL DEFAULT 1 COMMENT '手机端卡包分类展示：1展示 0不展示' AFTER `is_show`;
