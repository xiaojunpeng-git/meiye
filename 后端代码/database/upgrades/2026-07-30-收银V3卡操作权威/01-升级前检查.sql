-- upgrade_key: 20260730-021-cashier-v3-card-operation-authority-v1
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @cardop_db := DATABASE();
SET @cardop_failures := 0;

SELECT COUNT(*) INTO @cardop_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME='eb_database_upgrade_log' AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @cardop_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';
SET @cardop_registered := 0;
SET @cardop_registered_sql := IF(
  @cardop_upgrade_log_exists=1 AND @cardop_upgrade_key_column=1,
  'SELECT COUNT(*) INTO @cardop_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260730-021-cashier-v3-card-operation-authority-v1''',
  'SET @cardop_registered:=0'
);
PREPARE cardop_registered_stmt FROM @cardop_registered_sql;
EXECUTE cardop_registered_stmt;
DEALLOCATE PREPARE cardop_registered_stmt;

SELECT COUNT(*) INTO @cardop_legacy_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cardop_db AND ENGINE='InnoDB'
  AND TABLE_NAME IN (
    'eb_user_card_holder',
    'eb_store_order',
    'eb_store_order_cart_info',
    'eb_cashier_v3_entitlement_resource_version'
  );
-- 卡操作与 V3 Gateway 回执、事件及 Outbox 同事务提交；只建本包三张表
-- 不能让运行时再因缺少基础表出现半执行或裸数据库异常。
SELECT COUNT(*) INTO @cardop_gateway_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cardop_db AND ENGINE='InnoDB'
  AND TABLE_NAME IN (
    'eb_cashier_v3_command_receipt',
    'eb_cashier_v3_business_event',
    'eb_cashier_v3_outbox'
  );
SELECT COUNT(*) INTO @cardop_legacy_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cardop_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_user_card_holder.id','eb_user_card_holder.uid','eb_user_card_holder.oid',
  'eb_user_card_holder.store_id','eb_user_card_holder.write_start',
  'eb_user_card_holder.write_end','eb_user_card_holder.is_del',
  'eb_store_order.id','eb_store_order.uid','eb_store_order.store_id',
  'eb_store_order.paid','eb_store_order.is_del','eb_store_order.is_system_del',
  'eb_store_order.is_user_del','eb_store_order.refund_status',
  'eb_store_order.terminal_action','eb_store_order.card_upgrade_use_oid',
  'eb_store_order_cart_info.id','eb_store_order_cart_info.oid',
  'eb_store_order_cart_info.cart_type','eb_store_order_cart_info.product_type',
  'eb_store_order_cart_info.write_times','eb_store_order_cart_info.write_surplus_times',
  'eb_store_order_cart_info.is_writeoff','eb_store_order_cart_info.pay_price',
  'eb_cashier_v3_entitlement_resource_version.resource_kind',
  'eb_cashier_v3_entitlement_resource_version.resource_id',
  'eb_cashier_v3_entitlement_resource_version.member_id',
  'eb_cashier_v3_entitlement_resource_version.current_version'
);

SELECT COUNT(*) INTO @cardop_target_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cardop_db AND TABLE_NAME IN (
  'eb_cashier_v3_card_state',
  'eb_cashier_v3_card_operation',
  'eb_cashier_v3_card_operation_line'
);

-- Any target table means a partial DDL or an unregistered full DDL. Both
-- require a human-reviewed recovery path; this package never guesses.
SET @cardop_partial_ddl_safe_gate := IF(@cardop_target_table_count=0,0,1);
SET @cardop_failures := @cardop_failures
  + IF(@cardop_upgrade_log_exists=1,0,1)
  + IF(@cardop_upgrade_key_column=1,0,1)
  + IF(@cardop_registered=0,0,1)
  + IF(@cardop_legacy_tables=4,0,1)
  + IF(@cardop_gateway_tables=3,0,1)
  + IF(@cardop_legacy_columns=29,0,1)
  + @cardop_partial_ddl_safe_gate;

SELECT
  @cardop_db AS db_name,
  VERSION() AS mysql_version,
  @cardop_upgrade_log_exists AS upgrade_log_exists,
  @cardop_upgrade_key_column AS upgrade_key_column_valid,
  @cardop_registered AS already_registered,
  @cardop_legacy_tables AS legacy_table_count,
  @cardop_gateway_tables AS gateway_table_count,
  @cardop_legacy_columns AS legacy_column_count,
  @cardop_target_table_count AS existing_target_table_count,
  @cardop_partial_ddl_safe_gate AS partial_ddl_safe_gate_blocked,
  @cardop_failures AS precheck_failure_count;

SET @cardop_finish_sql := IF(
  @cardop_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result, ''PARTIAL_DDL_SAFE_GATE_OK'' AS recovery_gate',
  'SELECT * FROM STOP_CASHIER_V3_CARD_OPERATION_PRECHECK_FAILED'
);
PREPARE cardop_finish_stmt FROM @cardop_finish_sql;
EXECUTE cardop_finish_stmt;
DEALLOCATE PREPARE cardop_finish_stmt;
