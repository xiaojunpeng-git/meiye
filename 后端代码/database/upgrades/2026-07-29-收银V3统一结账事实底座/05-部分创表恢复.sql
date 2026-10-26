-- upgrade_key: 20260729-007-cashier-v3-checkout-facts-v1
-- Read-only interrupted multi-table DDL audit. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @cf_db := DATABASE();

SELECT COUNT(*) INTO @cf_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME='eb_database_upgrade_log';
SET @cf_registered := 0;
SET @cf_registered_sql := IF(
  @cf_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @cf_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-007-cashier-v3-checkout-facts-v1''',
  'SELECT 0 INTO @cf_registered'
);
PREPARE cf_registered_stmt FROM @cf_registered_sql;
EXECUTE cf_registered_stmt;
DEALLOCATE PREPARE cf_registered_stmt;

SELECT COUNT(*) INTO @cf_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME IN (
  'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
  'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
);

SELECT COUNT(*) INTO @cf_exact_tables
FROM (
  SELECT target.table_name
  FROM (
    SELECT 'eb_cashier_v3_sale_fact' AS table_name,42 AS column_count,10 AS index_count
    UNION ALL SELECT 'eb_cashier_v3_payment_fact',36,10
    UNION ALL SELECT 'eb_cashier_v3_balance_fact',39,10
    UNION ALL SELECT 'eb_cashier_v3_performance_fact',45,10
  ) target
  INNER JOIN information_schema.TABLES table_meta
    ON table_meta.TABLE_SCHEMA=@cf_db AND table_meta.TABLE_NAME=target.table_name
   AND table_meta.ENGINE='InnoDB' AND table_meta.TABLE_COLLATION='utf8mb4_general_ci'
  INNER JOIN (
    SELECT TABLE_NAME,COUNT(*) AS column_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME IN (
      'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
      'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
    )
    GROUP BY TABLE_NAME
  ) column_meta
    ON column_meta.TABLE_NAME=target.table_name
   AND column_meta.column_count=target.column_count
  INNER JOIN (
    SELECT TABLE_NAME,COUNT(*) AS index_count
    FROM (
      SELECT TABLE_NAME,INDEX_NAME
      FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME IN (
        'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
        'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
      )
      GROUP BY TABLE_NAME,INDEX_NAME
    ) index_names
    GROUP BY TABLE_NAME
  ) index_meta
    ON index_meta.TABLE_NAME=target.table_name
   AND index_meta.index_count=target.index_count
) exact_targets;

SELECT COUNT(*) INTO @cf_critical_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cf_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
    'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
  ) AND (
    (COLUMN_NAME='fact_id' AND COLUMN_TYPE='varchar(64)'
      AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='natural_key' AND COLUMN_TYPE='varchar(160)'
      AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='immutable_fingerprint' AND COLUMN_TYPE='char(64)'
      AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='business_date' AND DATA_TYPE='date' AND IS_NULLABLE='NO')
    OR (COLUMN_NAME='occurred_at' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%')
    OR (COLUMN_NAME='settled_at' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%')
    OR (COLUMN_NAME='recorded_at' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%')
  );

SELECT COUNT(*) INTO @cf_named_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cf_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
    'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
  ) AND (
    COLUMN_NAME IN (
      'id','fact_id','business_event_no','fact_type','fact_direction','natural_key',
      'command_idempotency_key','immutable_fingerprint','fact_version','reversal_of',
      'status','tenant_id','tenant_name_snapshot','organization_id',
      'organization_name_snapshot','organization_path_snapshot','store_id',
      'store_name_snapshot','member_id','member_name_snapshot','operator_id',
      'operator_name_snapshot','business_date','business_timezone','occurred_at',
      'settled_at','recorded_at','checkout_request_id','order_id','order_no_snapshot',
      'source_document_type','source_line_id'
    )
    OR (TABLE_NAME='eb_cashier_v3_sale_fact' AND COLUMN_NAME IN (
      'source_type','item_id','item_code_snapshot','item_name_snapshot',
      'category_id_snapshot','category_name_snapshot','quantity',
      'original_amount_cents','discount_amount_cents','sale_amount_cents'
    ))
    OR (TABLE_NAME='eb_cashier_v3_payment_fact' AND COLUMN_NAME IN (
      'payment_method','payment_authority_key','collection_reference','amount_cents'
    ))
    OR (TABLE_NAME='eb_cashier_v3_balance_fact' AND COLUMN_NAME IN (
      'balance_change_type','balance_account_id','account_version',
      'principal_delta_cents','bonus_delta_cents','principal_after_cents',
      'bonus_after_cents'
    ))
    OR (TABLE_NAME='eb_cashier_v3_performance_fact' AND COLUMN_NAME IN (
      'performance_type','employee_id','employee_name_snapshot',
      'employee_type_snapshot','employee_type_authority_version','role_snapshot',
      'allocation_weight_numerator','allocation_weight_denominator',
      'allocation_base_amount_cents','amount_cents','rule_code_snapshot',
      'rule_name_snapshot','rule_version_snapshot'
    ))
  );

SELECT IFNULL(SUM(CASE TABLE_NAME
  WHEN 'eb_cashier_v3_sale_fact' THEN 42
  WHEN 'eb_cashier_v3_payment_fact' THEN 36
  WHEN 'eb_cashier_v3_balance_fact' THEN 39
  WHEN 'eb_cashier_v3_performance_fact' THEN 45
  ELSE 0 END),0) INTO @cf_expected_named_columns
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME IN (
  'eb_cashier_v3_sale_fact','eb_cashier_v3_payment_fact',
  'eb_cashier_v3_balance_fact','eb_cashier_v3_performance_fact'
);

SET @cf_sale_rows := 0;
SET @cf_payment_rows := 0;
SET @cf_balance_rows := 0;
SET @cf_performance_rows := 0;
SET @cf_sale_count_sql := IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME='eb_cashier_v3_sale_fact'),
  'SELECT COUNT(*) INTO @cf_sale_rows FROM eb_cashier_v3_sale_fact',
  'SELECT 0 INTO @cf_sale_rows'
);
PREPARE cf_sale_count_stmt FROM @cf_sale_count_sql;
EXECUTE cf_sale_count_stmt;
DEALLOCATE PREPARE cf_sale_count_stmt;
SET @cf_payment_count_sql := IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME='eb_cashier_v3_payment_fact'),
  'SELECT COUNT(*) INTO @cf_payment_rows FROM eb_cashier_v3_payment_fact',
  'SELECT 0 INTO @cf_payment_rows'
);
PREPARE cf_payment_count_stmt FROM @cf_payment_count_sql;
EXECUTE cf_payment_count_stmt;
DEALLOCATE PREPARE cf_payment_count_stmt;
SET @cf_balance_count_sql := IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME='eb_cashier_v3_balance_fact'),
  'SELECT COUNT(*) INTO @cf_balance_rows FROM eb_cashier_v3_balance_fact',
  'SELECT 0 INTO @cf_balance_rows'
);
PREPARE cf_balance_count_stmt FROM @cf_balance_count_sql;
EXECUTE cf_balance_count_stmt;
DEALLOCATE PREPARE cf_balance_count_stmt;
SET @cf_performance_count_sql := IF(
  EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=@cf_db AND TABLE_NAME='eb_cashier_v3_performance_fact'),
  'SELECT COUNT(*) INTO @cf_performance_rows FROM eb_cashier_v3_performance_fact',
  'SELECT 0 INTO @cf_performance_rows'
);
PREPARE cf_performance_count_stmt FROM @cf_performance_count_sql;
EXECUTE cf_performance_count_stmt;
DEALLOCATE PREPARE cf_performance_count_stmt;
SET @cf_fact_rows := @cf_sale_rows + @cf_payment_rows + @cf_balance_rows + @cf_performance_rows;

SET @cf_partial_state := (@cf_target_tables>0 AND @cf_target_tables<4);
SET @cf_recovery_ready := @cf_partial_state=1
  AND @cf_upgrade_log_exists=1
  AND @cf_registered=0
  AND @cf_exact_tables=@cf_target_tables
  AND @cf_critical_columns=(@cf_target_tables*7)
  AND @cf_named_columns=@cf_expected_named_columns
  AND @cf_fact_rows=0;

SELECT
  @cf_target_tables AS existing_target_table_count,
  @cf_exact_tables AS exact_existing_table_count,
  @cf_critical_columns AS exact_critical_column_count,
  @cf_named_columns AS recognized_column_count,
  @cf_expected_named_columns AS expected_recognized_column_count,
  @cf_fact_rows AS existing_fact_row_count,
  @cf_registered AS upgrade_registered,
  @cf_recovery_ready AS partial_recovery_ready;

SET @cf_finish_sql := CASE
  WHEN @cf_target_tables=0 THEN 'SELECT * FROM STOP_CHECKOUT_FACT_PARTIAL_FRESH_STATE'
  WHEN @cf_target_tables=4 THEN 'SELECT * FROM STOP_CHECKOUT_FACT_PARTIAL_COMPLETE_STATE'
  WHEN @cf_upgrade_log_exists<>1 THEN 'SELECT * FROM STOP_CHECKOUT_FACT_PARTIAL_UPGRADE_LOG_MISSING'
  WHEN @cf_registered<>0 THEN 'SELECT * FROM STOP_CHECKOUT_FACT_PARTIAL_ALREADY_REGISTERED'
  WHEN @cf_exact_tables<>@cf_target_tables
    OR @cf_critical_columns<>@cf_target_tables*7
    OR @cf_named_columns<>@cf_expected_named_columns
    THEN 'SELECT * FROM STOP_CHECKOUT_FACT_PARTIAL_HETEROGENEOUS_SCHEMA'
  WHEN @cf_fact_rows<>0 THEN 'SELECT * FROM STOP_CHECKOUT_FACT_PARTIAL_ROWS_EXIST'
  ELSE 'SELECT ''PARTIAL_CREATE_RECOVERY_READY'' AS recovery_result'
END;
PREPARE cf_finish_stmt FROM @cf_finish_sql;
EXECUTE cf_finish_stmt;
DEALLOCATE PREPARE cf_finish_stmt;
