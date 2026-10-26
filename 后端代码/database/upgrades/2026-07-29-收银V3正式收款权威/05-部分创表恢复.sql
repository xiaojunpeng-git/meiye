-- upgrade_key: 20260729-012-cashier-v3-payment-collection-authority-v1
-- Read-only interrupted two-table DDL audit. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @pc_db := DATABASE();

SELECT COUNT(*) INTO @pc_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pc_db AND TABLE_NAME='eb_database_upgrade_log';
SET @pc_registered := 0;
SET @pc_registered_sql := IF(
  @pc_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @pc_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-012-cashier-v3-payment-collection-authority-v1''',
  'SELECT 0 INTO @pc_registered'
);
PREPARE pc_registered_stmt FROM @pc_registered_sql;
EXECUTE pc_registered_stmt;
DEALLOCATE PREPARE pc_registered_stmt;

SELECT COUNT(*) INTO @pc_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@pc_db
  AND TABLE_NAME IN (
    'eb_cashier_v3_payment_collection_batch',
    'eb_cashier_v3_payment_collection'
  );

SELECT COUNT(*) INTO @pc_exact_tables FROM (
  SELECT target.table_name
  FROM (
    SELECT 'eb_cashier_v3_payment_collection_batch' AS table_name,
      43 AS column_count,11 AS index_count
    UNION ALL SELECT 'eb_cashier_v3_payment_collection',51,15
  ) target
  INNER JOIN information_schema.TABLES table_meta
    ON table_meta.TABLE_SCHEMA=@pc_db AND table_meta.TABLE_NAME=target.table_name
   AND table_meta.ENGINE='InnoDB' AND table_meta.TABLE_COLLATION='utf8mb4_general_ci'
  INNER JOIN (
    SELECT TABLE_NAME,COUNT(*) AS column_count
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=@pc_db
      AND TABLE_NAME IN (
        'eb_cashier_v3_payment_collection_batch',
        'eb_cashier_v3_payment_collection'
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
      WHERE TABLE_SCHEMA=@pc_db
        AND TABLE_NAME IN (
          'eb_cashier_v3_payment_collection_batch',
          'eb_cashier_v3_payment_collection'
        )
      GROUP BY TABLE_NAME,INDEX_NAME
    ) index_names
    GROUP BY TABLE_NAME
  ) index_meta
    ON index_meta.TABLE_NAME=target.table_name
   AND index_meta.index_count=target.index_count
) exact_targets;

SET @pc_batch_rows := 0;
SET @pc_collection_rows := 0;
SET @pc_batch_count_sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=@pc_db AND TABLE_NAME='eb_cashier_v3_payment_collection_batch'
  ),
  'SELECT COUNT(*) INTO @pc_batch_rows FROM eb_cashier_v3_payment_collection_batch',
  'SELECT 0 INTO @pc_batch_rows'
);
PREPARE pc_batch_count_stmt FROM @pc_batch_count_sql;
EXECUTE pc_batch_count_stmt;
DEALLOCATE PREPARE pc_batch_count_stmt;
SET @pc_collection_count_sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=@pc_db AND TABLE_NAME='eb_cashier_v3_payment_collection'
  ),
  'SELECT COUNT(*) INTO @pc_collection_rows FROM eb_cashier_v3_payment_collection',
  'SELECT 0 INTO @pc_collection_rows'
);
PREPARE pc_collection_count_stmt FROM @pc_collection_count_sql;
EXECUTE pc_collection_count_stmt;
DEALLOCATE PREPARE pc_collection_count_stmt;

SET @pc_partial_state := (@pc_target_tables=1);
SET @pc_recovery_ready := @pc_partial_state=1
  AND @pc_upgrade_log_exists=1
  AND @pc_registered=0
  AND @pc_exact_tables=@pc_target_tables
  AND (@pc_batch_rows+@pc_collection_rows)=0;

SELECT
  @pc_target_tables AS existing_target_table_count,
  @pc_exact_tables AS exact_existing_table_count,
  @pc_batch_rows AS existing_batch_row_count,
  @pc_collection_rows AS existing_collection_row_count,
  @pc_registered AS upgrade_registered,
  @pc_recovery_ready AS partial_recovery_ready;

SET @pc_finish_sql := CASE
  WHEN @pc_target_tables=0 THEN 'SELECT * FROM STOP_PAYMENT_COLLECTION_PARTIAL_FRESH_STATE'
  WHEN @pc_target_tables=2 THEN 'SELECT * FROM STOP_PAYMENT_COLLECTION_PARTIAL_COMPLETE_STATE'
  WHEN @pc_upgrade_log_exists<>1 THEN 'SELECT * FROM STOP_PAYMENT_COLLECTION_PARTIAL_UPGRADE_LOG_MISSING'
  WHEN @pc_registered<>0 THEN 'SELECT * FROM STOP_PAYMENT_COLLECTION_PARTIAL_ALREADY_REGISTERED'
  WHEN @pc_exact_tables<>@pc_target_tables THEN 'SELECT * FROM STOP_PAYMENT_COLLECTION_PARTIAL_HETEROGENEOUS_SCHEMA'
  WHEN (@pc_batch_rows+@pc_collection_rows)<>0 THEN 'SELECT * FROM STOP_PAYMENT_COLLECTION_PARTIAL_ROWS_EXIST'
  ELSE 'SELECT ''PARTIAL_CREATE_RECOVERY_READY'' AS recovery_result'
END;
PREPARE pc_finish_stmt FROM @pc_finish_sql;
EXECUTE pc_finish_stmt;
DEALLOCATE PREPARE pc_finish_stmt;
