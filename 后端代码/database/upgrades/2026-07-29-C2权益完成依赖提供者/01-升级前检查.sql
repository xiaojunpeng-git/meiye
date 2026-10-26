-- upgrade_key: 20260729-003-c2-entitlement-provider-dependencies
-- Read-only. Rejects partial target DDL and missing legacy authorities.
SET NAMES utf8mb4;
SET @c2p_db := DATABASE();
SET @c2p_failures := 0;

SELECT COUNT(*) INTO @c2p_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME='eb_database_upgrade_log';

SET @c2p_c1_registered := 0;
SET @c2p_c1_sql := IF(
  @c2p_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @c2p_c1_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260727-001-cashier-v3-command-idem''',
  'SELECT 0 INTO @c2p_c1_registered'
);
PREPARE c2p_c1_stmt FROM @c2p_c1_sql;
EXECUTE c2p_c1_stmt;
DEALLOCATE PREPARE c2p_c1_stmt;

SET @c2p_already_registered := 0;
SET @c2p_registered_sql := IF(
  @c2p_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @c2p_already_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-003-c2-entitlement-provider-dependencies''',
  'SELECT 0 INTO @c2p_already_registered'
);
PREPARE c2p_registered_stmt FROM @c2p_registered_sql;
EXECUTE c2p_registered_stmt;
DEALLOCATE PREPARE c2p_registered_stmt;

SELECT COUNT(*) INTO @c2p_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_debt_guard',
  'eb_cashier_v3_entitlement_debt_guard_mutation',
  'eb_cashier_v3_staff_profile_version',
  'eb_cashier_v3_entitlement_occupation_version'
);

SELECT COUNT(*) INTO @c2p_authority_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c2p_db
  AND TABLE_NAME IN (
    'eb_store_order','eb_system_store_staff','eb_employee','eb_store_reservation_order'
  )
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @c2p_authority_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c2p_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_store_order.id',
  'eb_system_store_staff.id','eb_system_store_staff.employee_id',
  'eb_system_store_staff.store_id','eb_system_store_staff.staff_name',
  'eb_system_store_staff.status','eb_system_store_staff.is_del',
  'eb_employee.id','eb_employee.name','eb_employee.status','eb_employee.is_del',
  'eb_store_reservation_order.id','eb_store_reservation_order.store_id',
  'eb_store_reservation_order.cart_info_id','eb_store_reservation_order.status',
  'eb_store_reservation_order.is_del','eb_store_reservation_order.is_system_del'
);

SELECT COUNT(*) INTO @c2p_reservation_leading_index
FROM (
  SELECT INDEX_NAME,MIN(SEQ_IN_INDEX) AS first_seq,
    SUBSTRING_INDEX(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX),',',1) AS first_column
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c2p_db AND TABLE_NAME='eb_store_reservation_order'
  GROUP BY INDEX_NAME
  HAVING first_seq=1 AND first_column='cart_info_id'
) c2p_reservation_indexes;

SET @c2p_failures := @c2p_failures
  + IF(@c2p_upgrade_log_exists=1,0,1)
  + IF(@c2p_c1_registered=1,0,1)
  + IF(@c2p_already_registered=0,0,1)
  + IF(@c2p_target_count IN (0,4),0,1)
  + IF(@c2p_authority_table_count=4,0,1)
  + IF(@c2p_authority_column_count=17,0,1)
  + IF(@c2p_reservation_leading_index>=1,0,1);

SELECT
  @c2p_db AS db_name,
  VERSION() AS mysql_version,
  @c2p_c1_registered AS c1_registered,
  @c2p_already_registered AS already_registered,
  @c2p_target_count AS target_table_count,
  @c2p_authority_table_count AS authority_innodb_table_count,
  @c2p_authority_column_count AS authority_column_count,
  @c2p_reservation_leading_index AS reservation_leading_index_count,
  @c2p_failures AS precheck_failure_count;

SET @c2p_finish_sql := IF(
  @c2p_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_C2_ENTITLEMENT_PROVIDER_PRECHECK_FAILED'
);
PREPARE c2p_finish_stmt FROM @c2p_finish_sql;
EXECUTE c2p_finish_stmt;
DEALLOCATE PREPARE c2p_finish_stmt;
