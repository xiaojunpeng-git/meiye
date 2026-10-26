-- Display-only optional reservation lines. No backfill and no financial changes.
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_reservation_custom_service` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` VARCHAR(64) NOT NULL DEFAULT '0',
  `store_id` INT UNSIGNED NOT NULL,
  `reservation_id` BIGINT UNSIGNED NOT NULL,
  `service_name_id` BIGINT UNSIGNED NOT NULL,
  `name_snapshot` VARCHAR(64) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `created_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservation_custom_service` (`tenant_id`,`reservation_id`,`service_name_id`),
  KEY `idx_store_reservation` (`tenant_id`,`store_id`,`reservation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='预约附加展示服务，不参与收费或库存';
