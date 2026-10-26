-- This table records the exact catalog value changed by the one-time repair.
-- Creating it is a production schema change and requires the same instance authorization.
CREATE TABLE IF NOT EXISTS `mig_rh_20261012_platform_category_repair` (
  `product_id` int(10) NOT NULL,
  `old_cate_id` varchar(255) NOT NULL,
  `new_cate_id` varchar(255) NOT NULL,
  `source_product_ids` varchar(255) NOT NULL,
  `evidence_hash` char(64) NOT NULL,
  `backup_ref` varchar(255) NOT NULL,
  `applied_at` datetime NOT NULL,
  `reverted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
