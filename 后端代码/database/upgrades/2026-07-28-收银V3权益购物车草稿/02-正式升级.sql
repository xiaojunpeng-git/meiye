-- upgrade_key: 20260728-005-cashier-v3-entitlement-draft
-- MySQL 5.6 compatible. 01 must pass before this file is executed.
-- CREATE IF NOT EXISTS only makes exact-schema replay safe; 01 rejects partial or heterogeneous tables.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_workspace_draft` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'canonical cashier workspace id',
  `state_context_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'C1 state context bound to this draft',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'server-forced store id',
  `operator_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'authenticated operator id',
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'selected member; 0 only for guest mode',
  `customer_mode` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'guest' COMMENT 'member or guest; new workspace defaults to guest',
  `draft_status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'server-owned draft lifecycle state',
  `line_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'sha256 of canonical persisted lines',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  `update_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workspace_id` (`workspace_id`),
  UNIQUE KEY `uk_state_context_id` (`state_context_id`),
  KEY `idx_store_operator_time` (`store_id`,`operator_id`,`update_time`),
  KEY `idx_member_time` (`member_id`,`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 authoritative workspace draft; version remains in C1 resource contract';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_workspace_line` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workspace_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `line_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'stable line identity inside one workspace',
  `line_role` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'sale or entitlement_service',
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `holder_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'authoritative card or entitlement holder id',
  `source_detail_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'authoritative entitlement source detail id',
  `project_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'service project id',
  `quantity` int(10) unsigned NOT NULL DEFAULT '1' COMMENT 'selected service quantity; positive',
  `source_version` bigint(20) unsigned NOT NULL DEFAULT '1' COMMENT 'authoritative holder/source version; positive',
  `detail_version` bigint(20) unsigned NOT NULL DEFAULT '1' COMMENT 'authoritative source detail version; positive',
  `service_object` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'server-owned service object code',
  `is_experience` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0 normal; 1 downstream writeoff is an experience project',
  `craftsmen_json` mediumtext COMMENT 'draft craftsmen selections only; never a permission or entitlement authority',
  `display_snapshot_json` mediumtext COMMENT 'display-only source/card/project snapshot',
  `sort_no` int(10) unsigned NOT NULL DEFAULT '0',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  `update_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_workspace_line` (`workspace_id`,`line_key`),
  KEY `idx_workspace_role_sort` (`workspace_id`,`line_role`,`sort_no`,`id`),
  KEY `idx_member_role` (`member_id`,`line_role`),
  KEY `idx_entitlement_source` (`holder_id`,`source_detail_id`,`project_id`),
  KEY `idx_update_time` (`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 structured workspace cart lines';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_entitlement_resource_version` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `resource_kind` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'tenant-scoped entitlement resource kind',
  `resource_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'canonical entitlement resource id',
  `member_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT 'authoritative member owner used by data scope',
  `source_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'sha256 of canonical authority/source identity',
  `current_version` bigint(20) unsigned NOT NULL DEFAULT '1' COMMENT 'positive optimistic concurrency version',
  `last_action` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'last canonical action that advanced version',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  `update_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resource` (`resource_kind`,`resource_id`),
  KEY `idx_member_kind` (`member_id`,`resource_kind`),
  KEY `idx_update_time` (`update_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='tenant-shared cashier v3 entitlement authority version projection';

SELECT 'APPLY_OK' AS apply_result;
