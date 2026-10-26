-- upgrade_key: 20260731-002-cashier-v3-balance-ledger-idempotency-ascii
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @ascii_exact FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_key'
  AND DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH=128 AND IS_NULLABLE='YES'
  AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin';
SELECT COUNT(*) INTO @unique_exact FROM (
  SELECT NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    SUM(SUB_PART IS NOT NULL) AS prefix_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_user_money'
  GROUP BY INDEX_NAME,NON_UNIQUE
  HAVING NON_UNIQUE=0 AND index_columns='idempotency_key' AND prefix_parts=0
) idempotency_indexes;
SELECT @ascii_exact AS ascii_bin_column_count,@unique_exact AS unique_index_count;
SET @finish_sql := IF(@ascii_exact=1 AND @unique_exact=1,'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_BALANCE_LEDGER_IDEMPOTENCY_CHARSET_POSTCHECK_FAILED');
PREPARE finish_stmt FROM @finish_sql; EXECUTE finish_stmt; DEALLOCATE PREPARE finish_stmt;
