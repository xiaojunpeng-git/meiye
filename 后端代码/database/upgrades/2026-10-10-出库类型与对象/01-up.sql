-- Immutable attributes for new manual outbound documents; no historic category backfill.
CREATE TABLE IF NOT EXISTS `eb_inventory_manual_outbound_header` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) NOT NULL,
  `source_id` varchar(96) NOT NULL,
  `store_id` int unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `outbound_type` varchar(32) NOT NULL DEFAULT '',
  `outbound_recipient` varchar(100) NOT NULL DEFAULT '',
  `remark` varchar(500) NOT NULL DEFAULT '',
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_source` (`tenant_id`,`source_id`),
  KEY `idx_location_type` (`tenant_id`,`location_id`,`outbound_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手工出库单不可变业务属性';
