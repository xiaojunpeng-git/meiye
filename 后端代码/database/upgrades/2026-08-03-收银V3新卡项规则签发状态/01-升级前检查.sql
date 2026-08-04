-- upgrade_key: 20260803-005-cashier-v3-issued-card-rule-state-v1
-- Read-only precheck. Do not run 02 unless this ends in PRECHECK_OK.
SET NAMES utf8mb4;
SET @crs_db := DATABASE();
SET @crs_key := '20260803-005-cashier-v3-issued-card-rule-state-v1';
SET @crs_failures := 0;

SELECT COUNT(*) INTO @crs_registered FROM eb_database_upgrade_log WHERE upgrade_key=@crs_key;
SELECT COUNT(*) INTO @crs_dependencies FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=@crs_db AND ENGINE='InnoDB'
   AND TABLE_NAME IN ('eb_cashier_v3_card_purchase_receipt','eb_user_card_holder','eb_store_order_cart_info');
SELECT COUNT(*) INTO @crs_rule_columns FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=@crs_db
   AND ((TABLE_NAME='eb_store_product' AND COLUMN_NAME IN ('card_rule_type','card_rule_version','card_choice_limit','card_shared_times'))
     OR (TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='writeoff_amount'));
SELECT COUNT(*) INTO @crs_existing_targets FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=@crs_db
   AND TABLE_NAME IN ('eb_cashier_v3_card_rule_state','eb_cashier_v3_card_rule_component');

SET @crs_failures := @crs_failures
  + IF(@crs_registered=0,0,1)
  + IF(@crs_dependencies=3,0,1)
  + IF(@crs_rule_columns=5,0,1)
  + IF(@crs_existing_targets=0,0,1);

SELECT @crs_registered AS target_registered,
       @crs_dependencies AS dependency_table_count,
       @crs_rule_columns AS product_rule_column_count,
       @crs_existing_targets AS existing_target_table_count,
       @crs_failures AS precheck_failure_count;
SET @crs_finish_sql := IF(@crs_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_ISSUED_CARD_RULE_STATE_PRECHECK_FAILED');
PREPARE crs_finish_stmt FROM @crs_finish_sql;
EXECUTE crs_finish_stmt;
DEALLOCATE PREPARE crs_finish_stmt;
