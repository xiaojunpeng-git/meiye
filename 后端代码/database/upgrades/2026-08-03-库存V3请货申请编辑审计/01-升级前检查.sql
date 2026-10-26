-- upgrade_key: 20260803-003-inventory-v3-request-revision
SET NAMES utf8mb4;

SELECT DATABASE() AS current_database;
SET @db := DATABASE();
SET @upgrade_log_exists := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema=@db AND table_name='eb_database_upgrade_log'
);
SET @registered_sql := IF(
  @upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @already_registered FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260803-003-inventory-v3-request-revision''',
  'SET @already_registered:=1'
);
PREPARE registered_stmt FROM @registered_sql; EXECUTE registered_stmt; DEALLOCATE PREPARE registered_stmt;
SET @request_document_table_exists := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema=@db AND table_name='eb_inventory_stock_request_document'
);
SET @request_revision_table_exists := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema=@db AND table_name='eb_inventory_stock_request_revision'
);

SET @precheck_ok := @upgrade_log_exists=1
  AND @already_registered=0
  AND @request_document_table_exists=1;
SELECT @upgrade_log_exists AS upgrade_log_exists,
       @already_registered AS already_registered,
       @request_document_table_exists AS request_document_table_exists,
       @request_revision_table_exists AS request_revision_table_exists,
       @precheck_ok AS precheck_ok;
SET @finish_sql := IF(
  @precheck_ok,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''inventory request revision upgrade precheck failed'''
);
PREPARE finish_stmt FROM @finish_sql; EXECUTE finish_stmt; DEALLOCATE PREPARE finish_stmt;
