-- upgrade_key: 20260810-001-report-channel-fact-snapshot
-- 渠道归因以结账时选择的来源为准；不回读可变来源配置。
SET NAMES utf8mb4;

SET @db := DATABASE();
SET @table := 'eb_cashier_v3_sale_fact';
SET @column := 'business_source_primary_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_document_type`',
  'SELECT ''sale primary source already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_primary_name_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_primary_id`',
  'SELECT ''sale primary source name already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_secondary_id';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `business_source_primary_name_snapshot`',
  'SELECT ''sale secondary source already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_secondary_name_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_secondary_id`',
  'SELECT ''sale secondary source name already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @column := 'business_source_label_snapshot';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name=@column)=0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT '''' AFTER `business_source_secondary_name_snapshot`',
  'SELECT ''sale source label already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @index := 'idx_business_source_date';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name=@table AND index_name=@index)=0,
  'ALTER TABLE `eb_cashier_v3_sale_fact` ADD KEY `idx_business_source_date` (`tenant_id`,`store_id`,`business_source_primary_id`,`business_date`,`status`,`id`)',
  'SELECT ''sale source index already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 收款、余额和业绩事实也保留同一结账渠道快照，便于同口径下钻与对账。
SET @table := 'eb_cashier_v3_payment_fact';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name='business_source_primary_id')=0,
  'ALTER TABLE `eb_cashier_v3_payment_fact` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_document_type`, ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_primary_id`, ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `business_source_primary_name_snapshot`, ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_secondary_id`, ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT '''' AFTER `business_source_secondary_name_snapshot`, ADD KEY `idx_business_source_date` (`tenant_id`,`store_id`,`business_source_primary_id`,`business_date`,`status`,`id`)',
  'SELECT ''payment source snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table := 'eb_cashier_v3_balance_fact';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name='business_source_primary_id')=0,
  'ALTER TABLE `eb_cashier_v3_balance_fact` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_document_type`, ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_primary_id`, ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `business_source_primary_name_snapshot`, ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_secondary_id`, ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT '''' AFTER `business_source_secondary_name_snapshot`, ADD KEY `idx_business_source_date` (`tenant_id`,`store_id`,`business_source_primary_id`,`business_date`,`status`,`id`)',
  'SELECT ''balance source snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table := 'eb_cashier_v3_performance_fact';
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name=@table AND column_name='business_source_primary_id')=0,
  'ALTER TABLE `eb_cashier_v3_performance_fact` ADD COLUMN `business_source_primary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `source_document_type`, ADD COLUMN `business_source_primary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_primary_id`, ADD COLUMN `business_source_secondary_id` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `business_source_primary_name_snapshot`, ADD COLUMN `business_source_secondary_name_snapshot` varchar(64) NOT NULL DEFAULT '''' AFTER `business_source_secondary_id`, ADD COLUMN `business_source_label_snapshot` varchar(140) NOT NULL DEFAULT '''' AFTER `business_source_secondary_name_snapshot`, ADD KEY `idx_business_source_date` (`tenant_id`,`store_id`,`business_source_primary_id`,`business_date`,`status`,`id`)',
  'SELECT ''performance source snapshot already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'APPLY_OK' AS apply_result;
