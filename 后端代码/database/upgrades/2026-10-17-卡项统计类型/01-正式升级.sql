-- upgrade_key: 20261017-card-statistics-type
-- 0 普通卡、1 体验卡；现阶段只记录商品配置。新增非空默认值使旧卡项自动为 0。
SET @card_statistics_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_store_product'
    AND COLUMN_NAME = 'card_statistics_type');
SET @card_statistics_ddl := IF(@card_statistics_column_exists = 0,
  'ALTER TABLE `eb_store_product` ADD COLUMN `card_statistics_type` tinyint(1) unsigned NOT NULL DEFAULT 0 COMMENT ''卡项统计类型：0普通卡 1体验卡；仅记录配置'' AFTER `card_rule_type`',
  'SELECT ''card_statistics_type_already_present'' AS apply_result');
PREPARE card_statistics_stmt FROM @card_statistics_ddl;
EXECUTE card_statistics_stmt;
DEALLOCATE PREPARE card_statistics_stmt;
