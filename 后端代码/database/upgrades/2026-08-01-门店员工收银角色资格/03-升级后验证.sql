-- upgrade_key: 20260801-001-store-staff-cashier-role-eligibility
-- Read-only postcheck; MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @ssre_db := DATABASE();

SELECT COUNT(*) INTO @ssre_sales_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='cashier_salesperson_enabled'
  AND DATA_TYPE='tinyint' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1';
SELECT COUNT(*) INTO @ssre_craft_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ssre_db AND TABLE_NAME='eb_system_store_staff'
  AND COLUMN_NAME='cashier_craftsman_enabled'
  AND DATA_TYPE='tinyint' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1';

SELECT COUNT(*) INTO @ssre_invalid_rows
FROM `eb_system_store_staff`
WHERE `cashier_salesperson_enabled` NOT IN (0,1)
   OR `cashier_craftsman_enabled` NOT IN (0,1);

SELECT COUNT(*) INTO @ssre_active_rows
FROM `eb_system_store_staff`
WHERE `status`=1 AND `is_del`=0 AND `store_id`>0;
SELECT COUNT(*) INTO @ssre_active_sales_enabled
FROM `eb_system_store_staff`
WHERE `status`=1 AND `is_del`=0 AND `store_id`>0
  AND `cashier_salesperson_enabled`=1;
SELECT COUNT(*) INTO @ssre_active_craft_enabled
FROM `eb_system_store_staff`
WHERE `status`=1 AND `is_del`=0 AND `store_id`>0
  AND `cashier_craftsman_enabled`=1;

SELECT @ssre_sales_ok AS salesperson_column_ok,
  @ssre_craft_ok AS craftsman_column_ok,
  @ssre_invalid_rows AS invalid_role_flag_rows,
  @ssre_active_rows AS active_assignment_rows,
  @ssre_active_sales_enabled AS active_salesperson_enabled_rows,
  @ssre_active_craft_enabled AS active_craftsman_enabled_rows;

SET @ssre_abort := IF(
  @ssre_sales_ok=1 AND @ssre_craft_ok=1 AND @ssre_invalid_rows=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_STORE_STAFF_ROLE_ELIGIBILITY_POSTCHECK_FAILED');
PREPARE ssre_stmt FROM @ssre_abort;
EXECUTE ssre_stmt;
DEALLOCATE PREPARE ssre_stmt;
