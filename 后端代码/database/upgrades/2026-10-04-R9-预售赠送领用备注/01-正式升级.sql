-- One claim is one outbound document. Its editable note is separate from immutable stock facts.
-- Apply once per instance after backing up the claim table; this migration does not rewrite existing claims.
ALTER TABLE `eb_cashier_v3_presale_claim`
  ADD COLUMN `remark` varchar(500) NOT NULL DEFAULT '' AFTER `operator_name_snapshot`,
  ADD COLUMN `remark_version` bigint(20) unsigned NOT NULL DEFAULT '1' AFTER `remark`;

-- Edits remain attributable even when the current note is subsequently changed or cleared.
CREATE TABLE `eb_cashier_v3_presale_claim_remark_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `claim_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `previous_remark` varchar(500) NOT NULL DEFAULT '',
  `new_remark` varchar(500) NOT NULL DEFAULT '',
  `previous_version` bigint(20) unsigned NOT NULL,
  `operator_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `changed_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_claim_version` (`tenant_id`,`claim_id`,`previous_version`),
  KEY `idx_claim_history` (`tenant_id`,`claim_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='append-only claim note edit history';
