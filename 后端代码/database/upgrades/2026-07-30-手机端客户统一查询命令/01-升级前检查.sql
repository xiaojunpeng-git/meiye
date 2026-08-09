-- upgrade_key: 20260730-014-mobile-customer-unified-query-command-receipt
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) INTO @uq_dependencies
FROM information_schema.tables
WHERE table_schema=@db
  AND table_name IN ('eb_unified_query_preference','eb_unified_query_export_task');

SELECT COUNT(*) INTO @receipt_table
FROM information_schema.tables
WHERE table_schema=@db
  AND table_name='eb_mobile_customer_unified_query_command_receipt';

SET @precheck_ok := @uq_dependencies=2 AND @receipt_table IN (0,1);
SELECT @uq_dependencies AS unified_query_dependency_count,
       @receipt_table AS receipt_table_count,
       @precheck_ok AS precheck_ok;

SET @finish_sql := IF(@precheck_ok,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''mobile customer unified query command receipt precheck failed''');
PREPARE stmt FROM @finish_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
