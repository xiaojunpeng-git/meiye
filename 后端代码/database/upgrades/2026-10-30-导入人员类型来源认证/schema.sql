-- Imported employee type certification is separate from immutable business facts.
-- Runtime accepts a proof only while the complete frozen fact fingerprint matches.
CREATE TABLE IF NOT EXISTS `eb_cashier_v3_import_fact_provenance` (
  `tenant_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `fact_id` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `purpose` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `snapshot_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_batch_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_archive_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `plan_sha256` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`,`fact_id`,`purpose`),
  KEY `idx_provenance_plan` (`plan_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
