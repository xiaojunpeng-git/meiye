-- upgrade_key: 20260803-004-platform-card-product-rules
SET NAMES utf8mb4;
SET @card_db := DATABASE();
SET @card_failures := 0;

SELECT COUNT(*) INTO @card_product_columns FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@card_db AND TABLE_NAME='eb_store_product'
   AND COLUMN_NAME IN ('card_rule_type','card_rule_version','card_choice_limit','card_shared_times');
SELECT COUNT(*) INTO @card_related_column FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@card_db AND TABLE_NAME='eb_store_card_related'
   AND COLUMN_NAME='writeoff_amount' AND COLUMN_TYPE LIKE 'decimal(12,2)%' AND IS_NULLABLE='NO';
SELECT COUNT(*) INTO @card_rule_index FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=@card_db AND TABLE_NAME='eb_store_product' AND INDEX_NAME='idx_card_product_rule';
SELECT COUNT(*) INTO @card_invalid_product_rules FROM eb_store_product
 WHERE card_rule_type<>'' AND (product_type<>5 OR card_rule_type NOT IN ('normal','choice_kind','choice_count','time'));
SELECT COUNT(*) INTO @card_legacy_rows_changed FROM eb_store_product
 WHERE card_rule_type='' AND (card_rule_version<>0 OR card_choice_limit<>0 OR card_shared_times<>0);

SET @card_failures := @card_failures
  + IF(@card_product_columns=4,0,1)
  + IF(@card_related_column=1,0,1)
  + IF(@card_rule_index=4,0,1)
  + IF(@card_invalid_product_rules=0,0,1)
  + IF(@card_legacy_rows_changed=0,0,1);

SELECT @card_product_columns AS product_rule_columns,
       @card_related_column AS related_amount_column,
       @card_rule_index AS rule_index_columns,
       @card_invalid_product_rules AS invalid_product_rules,
       @card_legacy_rows_changed AS changed_legacy_rows,
       @card_failures AS postcheck_failure_count;
SET @card_finish_sql := IF(@card_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_PLATFORM_CARD_PRODUCT_RULE_POSTCHECK_FAILED');
PREPARE card_finish_stmt FROM @card_finish_sql;
EXECUTE card_finish_stmt;
DEALLOCATE PREPARE card_finish_stmt;
