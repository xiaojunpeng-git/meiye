-- One-time RH repair audit. Apply only after fixed-version acceptance and an instance backup.
CREATE TABLE IF NOT EXISTS `mig_rh_20261013_category_and_card_repair` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `repair_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `before_json` text NOT NULL,
  `after_json` text NOT NULL,
  `evidence_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `backup_ref` varchar(255) NOT NULL,
  `applied_at` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_repair_source` (`repair_kind`, `source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
