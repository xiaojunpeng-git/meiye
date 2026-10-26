-- upgrade_key: 20260810-002-customer-lifecycle-first-course-attribution
-- MySQL 5.6 compatible and re-runnable.
SET NAMES utf8mb4;
SET @clf_db := DATABASE();

SET @clf_table := 'eb_cashier_v3_business_source';
SET @clf_column := 'attribution_type';
SET @clf_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME=@clf_table AND COLUMN_NAME=@clf_column)=0,
  'ALTER TABLE `eb_cashier_v3_business_source` ADD COLUMN `attribution_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''other'' AFTER `require_secondary`',
  'SELECT ''business source attribution type already exists'' AS apply_note');
PREPARE clf_stmt FROM @clf_sql; EXECUTE clf_stmt; DEALLOCATE PREPARE clf_stmt;

-- Keep the selected source type alongside every checkout fact. The type is a
-- historical snapshot and is never resolved again during report queries.
SET @clf_fact_tables := 'cashier_v3_sale_fact,cashier_v3_payment_fact,cashier_v3_balance_fact,cashier_v3_performance_fact';
SET @clf_fact_table := '';
SET @clf_fact_sql := '';
-- MySQL 5.6 lacks ALTER TABLE ADD COLUMN IF NOT EXISTS, so use the same
-- idempotent information_schema guard for each known immutable fact table.
SET @clf_table := 'eb_cashier_v3_sale_fact';
SET @clf_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME=@clf_table AND COLUMN_NAME='source_attribution_type_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_sale_fact` ADD COLUMN `source_attribution_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''other'' AFTER `business_source_label_snapshot`', 'SELECT ''sale source type snapshot already exists'' AS apply_note'); PREPARE clf_stmt FROM @clf_sql; EXECUTE clf_stmt; DEALLOCATE PREPARE clf_stmt;
SET @clf_table := 'eb_cashier_v3_payment_fact';
SET @clf_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME=@clf_table AND COLUMN_NAME='source_attribution_type_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_payment_fact` ADD COLUMN `source_attribution_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''other'' AFTER `business_source_label_snapshot`', 'SELECT ''payment source type snapshot already exists'' AS apply_note'); PREPARE clf_stmt FROM @clf_sql; EXECUTE clf_stmt; DEALLOCATE PREPARE clf_stmt;
SET @clf_table := 'eb_cashier_v3_balance_fact';
SET @clf_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME=@clf_table AND COLUMN_NAME='source_attribution_type_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_balance_fact` ADD COLUMN `source_attribution_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''other'' AFTER `business_source_label_snapshot`', 'SELECT ''balance source type snapshot already exists'' AS apply_note'); PREPARE clf_stmt FROM @clf_sql; EXECUTE clf_stmt; DEALLOCATE PREPARE clf_stmt;
SET @clf_table := 'eb_cashier_v3_performance_fact';
SET @clf_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@clf_db AND TABLE_NAME=@clf_table AND COLUMN_NAME='source_attribution_type_snapshot')=0, 'ALTER TABLE `eb_cashier_v3_performance_fact` ADD COLUMN `source_attribution_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''other'' AFTER `business_source_label_snapshot`', 'SELECT ''performance source type snapshot already exists'' AS apply_note'); PREPARE clf_stmt FROM @clf_sql; EXECUTE clf_stmt; DEALLOCATE PREPARE clf_stmt;

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_customer_lifecycle_fact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fact_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `natural_key` varchar(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `event_type` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `lifecycle_stage_after` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `related_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `related_order_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `source_primary_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT '',
  `source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT '',
  `source_label_snapshot` varchar(140) NOT NULL DEFAULT '',
  `source_attribution_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'other',
  `referrer_member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `is_guest` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `business_date` date NOT NULL,
  `occurred_at` bigint(20) unsigned NOT NULL,
  `recorded_at` bigint(20) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'effective',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fact_id` (`fact_id`),
  UNIQUE KEY `uk_tenant_natural` (`tenant_id`,`natural_key`),
  KEY `idx_scope_member_date` (`tenant_id`,`store_id`,`member_id`,`business_date`,`id`),
  KEY `idx_event_date` (`tenant_id`,`event_type`,`business_date`,`id`),
  KEY `idx_source_date` (`tenant_id`,`source_primary_id`,`business_date`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 immutable customer lifecycle facts';

CREATE TABLE IF NOT EXISTS `eb_cashier_v3_customer_lifecycle_projection` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `organization_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `store_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `lifecycle_stage` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `has_pending_conversion` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `pending_conversion_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `first_course_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `first_course_order_no_snapshot` varchar(64) NOT NULL DEFAULT '',
  `first_course_completed_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `first_course_business_date` date DEFAULT NULL,
  `first_course_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `first_course_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT '',
  `first_course_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `first_course_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT '',
  `first_course_source_label_snapshot` varchar(140) NOT NULL DEFAULT '',
  `first_course_source_attribution_type_snapshot` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'other',
  `referrer_member_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `is_guest` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `service_visit_count` int(10) unsigned NOT NULL DEFAULT '0',
  `first_service_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `last_service_at` bigint(20) unsigned NOT NULL DEFAULT '0',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `created_at` bigint(20) unsigned NOT NULL,
  `updated_at` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_member` (`tenant_id`,`member_id`),
  KEY `idx_scope_stage` (`tenant_id`,`store_id`,`lifecycle_stage`,`id`),
  KEY `idx_first_course_source` (`tenant_id`,`first_course_source_primary_id`,`first_course_business_date`,`id`),
  KEY `idx_last_service` (`tenant_id`,`store_id`,`last_service_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='cashier v3 current customer lifecycle projection';
