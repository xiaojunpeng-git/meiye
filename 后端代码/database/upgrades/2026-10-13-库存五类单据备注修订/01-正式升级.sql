-- Remarks are mutable projections; stock movements, amounts and settlement state stay immutable.
-- Apply once per independent customer database after backup. No existing row is rewritten.
CREATE TABLE `eb_inventory_document_remark` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `document_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_id` varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `remark` varchar(500) NOT NULL DEFAULT '',
  `line_remarks_json` mediumtext NOT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updated_by_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `updated_by_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `updated_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document` (`tenant_id`,`document_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Current editable remarks for five inventory document types';

-- One revision is one atomic header-and-lines edit; retained even if later cleared or changed.
CREATE TABLE `eb_inventory_document_remark_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `remark_id` bigint(20) unsigned NOT NULL,
  `previous_version` bigint(20) unsigned NOT NULL,
  `previous_remark` varchar(500) NOT NULL DEFAULT '',
  `new_remark` varchar(500) NOT NULL DEFAULT '',
  `previous_line_remarks_json` mediumtext NOT NULL,
  `new_line_remarks_json` mediumtext NOT NULL,
  `operator_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operator_id` bigint(20) unsigned NOT NULL,
  `changed_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_remark_version` (`remark_id`,`previous_version`),
  KEY `idx_remark_history` (`remark_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Append-only inventory document remark revisions';
