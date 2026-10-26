-- upgrade_key: 20260729-014-cashier-v3-entitlement-completion-persistence-v1
-- Read only. Partial target DDL is rejected and must pass 05 first.
SET NAMES utf8mb4;
SET @ecp_db := DATABASE();
SET @ecp_failures := 0;

SELECT COUNT(*) INTO @ecp_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @ecp_target_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME IN (
  'eb_cashier_v3_entitlement_completion_receipt',
  'eb_cashier_v3_entitlement_writeoff_fact',
  'eb_cashier_v3_entitlement_service_fact'
);

SELECT COUNT(*) INTO @ecp_prerequisite_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@ecp_db AND TABLE_NAME IN (
  'eb_user_card_holder','eb_store_order_cart_info','eb_store_order',
  'eb_store_reservation_order','eb_store_debt',
  'eb_cashier_v3_entitlement_resource_version','eb_cashier_v3_performance_fact'
) AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @ecp_prerequisite_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@ecp_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_user_card_holder.id','eb_user_card_holder.uid','eb_user_card_holder.oid',
  'eb_user_card_holder.write_surplus_times','eb_user_card_holder.is_del',
  'eb_store_order_cart_info.id','eb_store_order_cart_info.oid',
  'eb_store_order_cart_info.product_id','eb_store_order_cart_info.cart_type',
  'eb_store_order_cart_info.product_type','eb_store_order_cart_info.write_times',
  'eb_store_order_cart_info.write_surplus_times','eb_store_order_cart_info.is_writeoff',
  'eb_store_order.id','eb_store_order.uid','eb_store_order.paid',
  'eb_store_order.is_del','eb_store_order.refund_status',
  'eb_store_reservation_order.id','eb_store_reservation_order.cart_info_id',
  'eb_store_reservation_order.status','eb_store_debt.order_id','eb_store_debt.status',
  'eb_cashier_v3_entitlement_resource_version.resource_kind',
  'eb_cashier_v3_entitlement_resource_version.resource_id',
  'eb_cashier_v3_entitlement_resource_version.source_fingerprint',
  'eb_cashier_v3_entitlement_resource_version.current_version',
  'eb_cashier_v3_performance_fact.fact_id',
  'eb_cashier_v3_performance_fact.natural_key',
  'eb_cashier_v3_performance_fact.performance_type',
  'eb_cashier_v3_performance_fact.employee_id',
  'eb_cashier_v3_performance_fact.amount_cents'
);

SET @ecp_registered := 0;
SET @ecp_registered_sql := IF(
  @ecp_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @ecp_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-014-cashier-v3-entitlement-completion-persistence-v1''',
  'SELECT 0 INTO @ecp_registered'
);
PREPARE ecp_registered_stmt FROM @ecp_registered_sql;
EXECUTE ecp_registered_stmt;
DEALLOCATE PREPARE ecp_registered_stmt;

SET @ecp_failures := @ecp_failures
  + IF(@ecp_upgrade_log_exists=1,0,1)
  + IF(@ecp_target_count IN (0,3),0,1)
  + IF(@ecp_prerequisite_tables=7,0,1)
  + IF(@ecp_prerequisite_columns=32,0,1)
  + IF(@ecp_registered IN (0,1),0,1);

SELECT @ecp_db AS db_name,VERSION() AS mysql_version,
  @ecp_target_count AS target_table_count,
  @ecp_prerequisite_tables AS prerequisite_table_count,
  @ecp_prerequisite_columns AS prerequisite_column_count,
  @ecp_registered AS already_registered,
  @ecp_failures AS precheck_failure_count;

SET @ecp_finish_sql := IF(
  @ecp_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_ENTITLEMENT_COMPLETION_PRECHECK_FAILED'
);
PREPARE ecp_finish_stmt FROM @ecp_finish_sql;
EXECUTE ecp_finish_stmt;
DEALLOCATE PREPARE ecp_finish_stmt;
