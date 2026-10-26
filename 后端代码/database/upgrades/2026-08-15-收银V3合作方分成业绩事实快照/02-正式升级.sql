-- upgrade_key: 20260815-003-cashier-v3-partner-share-fact-snapshot
-- MySQL 5.6 compatible, repeatable and non-destructive.
SET NAMES utf8mb4;
SET @partner_share_db := DATABASE();

-- The two source facts are prerequisites. Do not silently create an incomplete
-- substitute table when an earlier migration was skipped.
SET @partner_share_sql := IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact')=1
  AND (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact')=1
  AND (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_category_config' AND COLUMN_NAME='partner_default_ratio')=1,
  'SELECT ''PARTNER_SHARE_PREREQUISITES_OK'' AS apply_result',
  'SELECT * FROM STOP_PARTNER_SHARE_FACT_PREREQUISITE_MISSING'
);
PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;

SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND COLUMN_NAME='cash_performance_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_report_sale_dimension_fact` ADD COLUMN `cash_performance_amount_cents` bigint(20) NOT NULL DEFAULT 0 AFTER `partner_name_snapshot`',
  'SELECT ''dimension cash performance exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
-- Columns are deliberately added one at a time. A server interruption after
-- any ALTER remains safely resumable on the next execution.
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND COLUMN_NAME='partner_category_id_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_report_sale_dimension_fact` ADD COLUMN `partner_category_id_snapshot` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `cash_performance_amount_cents`', 'SELECT ''dimension partner category exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND COLUMN_NAME='partner_category_name_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_report_sale_dimension_fact` ADD COLUMN `partner_category_name_snapshot` varchar(128) NOT NULL DEFAULT '''' AFTER `partner_category_id_snapshot`', 'SELECT ''dimension partner category name exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND COLUMN_NAME='partner_category_path_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_report_sale_dimension_fact` ADD COLUMN `partner_category_path_snapshot` varchar(512) NOT NULL DEFAULT '''' AFTER `partner_category_name_snapshot`', 'SELECT ''dimension partner category path exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND COLUMN_NAME='partner_default_ratio_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_report_sale_dimension_fact` ADD COLUMN `partner_default_ratio_snapshot` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `partner_category_path_snapshot`', 'SELECT ''dimension partner ratio exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND COLUMN_NAME='partner_config_version_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_report_sale_dimension_fact` ADD COLUMN `partner_config_version_snapshot` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `partner_default_ratio_snapshot`', 'SELECT ''dimension partner config version exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND COLUMN_NAME='partner_share_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_report_sale_dimension_fact` ADD COLUMN `partner_share_amount_cents` bigint(20) NOT NULL DEFAULT 0 AFTER `partner_config_version_snapshot`', 'SELECT ''dimension partner share exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;

SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact' AND COLUMN_NAME='partner_category_id_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_card_sale_category_allocation_fact` ADD COLUMN `partner_category_id_snapshot` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `partner_name_snapshot`', 'SELECT ''card partner category exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact' AND COLUMN_NAME='partner_category_name_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_card_sale_category_allocation_fact` ADD COLUMN `partner_category_name_snapshot` varchar(128) NOT NULL DEFAULT '''' AFTER `partner_category_id_snapshot`', 'SELECT ''card partner category name exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact' AND COLUMN_NAME='partner_category_path_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_card_sale_category_allocation_fact` ADD COLUMN `partner_category_path_snapshot` varchar(512) NOT NULL DEFAULT '''' AFTER `partner_category_name_snapshot`', 'SELECT ''card partner category path exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact' AND COLUMN_NAME='partner_default_ratio_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_card_sale_category_allocation_fact` ADD COLUMN `partner_default_ratio_snapshot` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `partner_category_path_snapshot`', 'SELECT ''card partner ratio exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact' AND COLUMN_NAME='partner_config_version_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_card_sale_category_allocation_fact` ADD COLUMN `partner_config_version_snapshot` bigint(20) unsigned NOT NULL DEFAULT 0 AFTER `partner_default_ratio_snapshot`', 'SELECT ''card partner config version exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact' AND COLUMN_NAME='partner_share_amount_cents')=0,
  'ALTER TABLE `eb_cashier_v3_card_sale_category_allocation_fact` ADD COLUMN `partner_share_amount_cents` bigint(20) NOT NULL DEFAULT 0 AFTER `partner_config_version_snapshot`', 'SELECT ''card partner share exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;

SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_report_sale_dimension_fact' AND INDEX_NAME='idx_scope_partner_category')=0,
  'ALTER TABLE `eb_cashier_v3_report_sale_dimension_fact` ADD KEY `idx_scope_partner_category` (`tenant_id`,`store_id`,`business_date`,`partner_category_id_snapshot`,`id`)',
  'SELECT ''dimension partner index exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;
SET @partner_share_sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@partner_share_db AND TABLE_NAME='eb_cashier_v3_card_sale_category_allocation_fact' AND INDEX_NAME='idx_scope_partner_category')=0,
  'ALTER TABLE `eb_cashier_v3_card_sale_category_allocation_fact` ADD KEY `idx_scope_partner_category` (`tenant_id`,`store_id`,`business_date`,`partner_category_id_snapshot`,`status`,`id`)',
  'SELECT ''card allocation partner index exists'''); PREPARE partner_share_stmt FROM @partner_share_sql; EXECUTE partner_share_stmt; DEALLOCATE PREPARE partner_share_stmt;

SELECT 'APPLY_OK' AS apply_result;
