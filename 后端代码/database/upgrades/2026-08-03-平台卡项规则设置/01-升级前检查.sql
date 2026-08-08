-- upgrade_key: 20260803-004-platform-card-product-rules
-- Read-only precheck. Do not run 02 unless this ends in PRECHECK_OK.
SET NAMES utf8mb4;
SET @card_db := DATABASE();
SET @card_key := '20260803-004-platform-card-product-rules';
SET @card_failures := 0;

SELECT COUNT(*) INTO @card_registered FROM eb_database_upgrade_log WHERE upgrade_key=@card_key;
SELECT COUNT(*) INTO @card_tables FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=@card_db AND TABLE_NAME IN ('eb_store_product','eb_store_card_related') AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @card_existing_columns FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@card_db
   AND ((TABLE_NAME='eb_store_product' AND COLUMN_NAME IN ('card_rule_type','card_rule_version','card_choice_limit','card_shared_times'))
     OR (TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='writeoff_amount'));

SET @card_failures := @card_failures
  + IF(@card_registered=0,0,1)
  + IF(@card_tables=2,0,1)
  + IF(@card_existing_columns=0,0,1);

SELECT @card_registered AS target_registered,
       @card_tables AS core_table_count,
       @card_existing_columns AS existing_target_column_count,
       @card_failures AS precheck_failure_count;
SET @card_finish_sql := IF(@card_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_PLATFORM_CARD_PRODUCT_RULE_PRECHECK_FAILED');
PREPARE card_finish_stmt FROM @card_finish_sql;
EXECUTE card_finish_stmt;
DEALLOCATE PREPARE card_finish_stmt;
