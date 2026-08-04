-- upgrade_key: 20260801-001-store-staff-cashier-role-eligibility
-- Read-only precheck; MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ssre_db := DATABASE();

SELECT COUNT(*) INTO @ssre_staff_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @ssre_anchor_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='can_choose';

SELECT COUNT(*) INTO @ssre_sales_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='cashier_salesperson_enabled';
SELECT COUNT(*) INTO @ssre_sales_definition_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='cashier_salesperson_enabled'
  AND DATA_TYPE='tinyint' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1';

SELECT COUNT(*) INTO @ssre_craft_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='cashier_craftsman_enabled';
SELECT COUNT(*) INTO @ssre_craft_definition_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='cashier_craftsman_enabled'
  AND DATA_TYPE='tinyint' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1';

SET @ssre_failures := IF(@ssre_staff_table=1,0,1)
  + IF(@ssre_anchor_column=1,0,1)
  + IF(@ssre_sales_column IN (0,1),0,1)
  + IF(@ssre_sales_column=0 OR @ssre_sales_definition_ok=1,0,1)
  + IF(@ssre_craft_column IN (0,1),0,1)
  + IF(@ssre_craft_column=0 OR @ssre_craft_definition_ok=1,0,1);

SELECT @ssre_staff_table AS staff_table_ready,
  @ssre_anchor_column AS can_choose_anchor_ready,
  @ssre_sales_column AS salesperson_column_count,
  @ssre_sales_definition_ok AS salesperson_definition_ok,
  @ssre_craft_column AS craftsman_column_count,
  @ssre_craft_definition_ok AS craftsman_definition_ok,
  @ssre_failures AS precheck_failure_count;

SET @ssre_abort := IF(@ssre_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_STORE_STAFF_ROLE_ELIGIBILITY_PRECHECK_FAILED');
PREPARE ssre_stmt FROM @ssre_abort;
EXECUTE ssre_stmt;
DEALLOCATE PREPARE ssre_stmt;
