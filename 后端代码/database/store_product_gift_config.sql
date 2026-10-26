-- 赠送配置表（商品、储值档位等长期持久化存储）
-- config JSON 结构：{"product":[{项目/商品/卡项...}],"coupon":[{优惠券...}]}
CREATE TABLE IF NOT EXISTS `eb_store_gift_config` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `gift_type` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT '赠送类型：1=商品 2=储值档位',
  `relation_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '关联ID：商品ID或储值档位ID',
  `config` text COMMENT '赠送配置JSON：{"product":[],"coupon":[]}',
  `add_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '添加时间',
  `update_time` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gift_type_relation` (`gift_type`, `relation_id`),
  KEY `idx_relation_id` (`relation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='赠送配置（商品/储值等）';

-- 可选：从旧版商品赠送表迁移（如已创建 eb_store_product_gift_config）
-- INSERT INTO `eb_store_gift_config` (`gift_type`, `relation_id`, `config`, `add_time`, `update_time`)
-- SELECT 1, `product_id`, `config`, `add_time`, `update_time`
-- FROM `eb_store_product_gift_config`
-- ON DUPLICATE KEY UPDATE
--   `config` = VALUES(`config`),
--   `update_time` = VALUES(`update_time`);

-- 可选：从 cache 表迁移商品赠送历史数据
-- INSERT INTO `eb_store_gift_config` (`gift_type`, `relation_id`, `config`, `add_time`, `update_time`)
-- SELECT
--   1,
--   CAST(SUBSTRING(`key`, 21) AS UNSIGNED),
--   `result`,
--   `add_time`,
--   `add_time`
-- FROM `eb_cache`
-- WHERE `key` LIKE 'product_gift_config_%'
--   AND `result` IS NOT NULL
--   AND `result` != ''
-- ON DUPLICATE KEY UPDATE
--   `config` = VALUES(`config`),
--   `update_time` = VALUES(`update_time`);

-- 可选：从 cache 表迁移储值档位赠送历史数据
-- INSERT INTO `eb_store_gift_config` (`gift_type`, `relation_id`, `config`, `add_time`, `update_time`)
-- SELECT
--   2,
--   CAST(SUBSTRING(`key`, 22) AS UNSIGNED),
--   `result`,
--   `add_time`,
--   `add_time`
-- FROM `eb_cache`
-- WHERE `key` LIKE 'recharge_gift_config_%'
--   AND `result` IS NOT NULL
--   AND `result` != ''
-- ON DUPLICATE KEY UPDATE
--   `config` = VALUES(`config`),
--   `update_time` = VALUES(`update_time`);

-- 迁移完成后可删除旧表
-- DROP TABLE IF EXISTS `eb_store_product_gift_config`;
