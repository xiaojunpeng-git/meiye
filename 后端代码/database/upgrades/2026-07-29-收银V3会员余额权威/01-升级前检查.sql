-- upgrade_key: 20260729-009-cashier-v3-member-balance-authority
-- Read-only precheck. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @mba_db := DATABASE();
SET @mba_failures := 0;

SELECT COUNT(*) INTO @mba_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_database_upgrade_log';

SET @mba_registered := 0;
SET @mba_registered_sql := IF(
  @mba_upgrade_log_exists=1,
  'SELECT COUNT(*) INTO @mba_registered FROM eb_database_upgrade_log WHERE upgrade_key=''20260729-009-cashier-v3-member-balance-authority''',
  'SELECT 0 INTO @mba_registered'
);
PREPARE mba_registered_stmt FROM @mba_registered_sql;
EXECUTE mba_registered_stmt;
DEALLOCATE PREPARE mba_registered_stmt;

SELECT COUNT(*) INTO @mba_dependency_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@mba_db
  AND TABLE_NAME IN ('eb_user','eb_user_money')
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @mba_dependency_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN (
  'eb_user.uid','eb_user.now_money','eb_user.ben_money','eb_user.give_money',
  'eb_user.status','eb_user.is_del','eb_user.delete_time','eb_user.belong_store_id',
  'eb_user_money.id','eb_user_money.uid','eb_user_money.link_id','eb_user_money.type',
  'eb_user_money.title','eb_user_money.number','eb_user_money.balance','eb_user_money.mark',
  'eb_user_money.pm','eb_user_money.status','eb_user_money.ben_money',
  'eb_user_money.give_money','eb_user_money.add_time'
);

SELECT COUNT(*) INTO @mba_money_columns_exact
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db
  AND ((TABLE_NAME='eb_user' AND COLUMN_NAME IN ('now_money','ben_money','give_money'))
    OR (TABLE_NAME='eb_user_money' AND COLUMN_NAME IN ('number','balance','ben_money','give_money')))
  AND DATA_TYPE='decimal' AND NUMERIC_SCALE=2 AND IS_NULLABLE='NO';

SELECT COUNT(*) INTO @mba_version_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user' AND COLUMN_NAME='balance_version';

SELECT COUNT(*) INTO @mba_version_column_exact
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user' AND COLUMN_NAME='balance_version'
  AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%'
  AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1';

SELECT COUNT(*) INTO @mba_ledger_target_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money'
  AND COLUMN_NAME IN (
    'ben_change_amount','give_change_amount','idempotency_key','idempotency_fingerprint'
  );

SELECT COUNT(*) INTO @mba_ledger_target_column_exact
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money' AND (
  (COLUMN_NAME IN ('ben_change_amount','give_change_amount')
    AND DATA_TYPE='decimal' AND NUMERIC_PRECISION=12 AND NUMERIC_SCALE=2
    AND IS_NULLABLE='YES' AND COLUMN_DEFAULT IS NULL)
  OR
  (COLUMN_NAME='idempotency_key' AND DATA_TYPE='varchar'
    AND CHARACTER_MAXIMUM_LENGTH=128 AND IS_NULLABLE='YES'
    AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
  OR
  (COLUMN_NAME='idempotency_fingerprint' AND DATA_TYPE='char'
    AND CHARACTER_MAXIMUM_LENGTH=64 AND IS_NULLABLE='YES'
    AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
);

SELECT COUNT(*) INTO @mba_idempotency_unique_exact
FROM (
  SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money'
  GROUP BY INDEX_NAME,NON_UNIQUE
  HAVING NON_UNIQUE=0 AND index_columns='idempotency_key'
    AND SUM(IF(SUB_PART IS NULL,0,1))=0
) mba_unique;

SET @mba_duplicate_idempotency := 0;
SET @mba_duplicate_sql := IF(
  EXISTS(
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_key'
  ),
  'SELECT COUNT(*) INTO @mba_duplicate_idempotency FROM (SELECT idempotency_key FROM eb_user_money WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*)>1) duplicate_keys',
  'SELECT 0 INTO @mba_duplicate_idempotency'
);
PREPARE mba_duplicate_stmt FROM @mba_duplicate_sql;
EXECUTE mba_duplicate_stmt;
DEALLOCATE PREPARE mba_duplicate_stmt;

SELECT COUNT(*) INTO @mba_before_update_triggers
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=@mba_db AND EVENT_OBJECT_TABLE='eb_user'
  AND ACTION_TIMING='BEFORE' AND EVENT_MANIPULATION='UPDATE';

SELECT COUNT(*) INTO @mba_named_trigger_count
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=@mba_db AND TRIGGER_NAME='eb_user_balance_version_bu';

SELECT COUNT(*) INTO @mba_named_trigger_exact
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=@mba_db AND TRIGGER_NAME='eb_user_balance_version_bu'
  AND EVENT_OBJECT_TABLE='eb_user' AND ACTION_TIMING='BEFORE' AND EVENT_MANIPULATION='UPDATE'
  AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(ACTION_STATEMENT,'`',''),' ',''),CHAR(9),''),CHAR(10),''))
      LIKE '%new.now_money<=>old.now_money%'
  AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(ACTION_STATEMENT,'`',''),' ',''),CHAR(9),''),CHAR(10),''))
      LIKE '%new.ben_money<=>old.ben_money%'
  AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(ACTION_STATEMENT,'`',''),' ',''),CHAR(9),''),CHAR(10),''))
      LIKE '%new.give_money<=>old.give_money%'
  AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(ACTION_STATEMENT,'`',''),' ',''),CHAR(9),''),CHAR(10),''))
      LIKE '%new.balance_version=old.balance_version+1%'
  AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(ACTION_STATEMENT,'`',''),' ',''),CHAR(9),''),CHAR(10),''))
      LIKE '%new.balance_version=old.balance_version%';

SET @mba_invalid_version_rows := 0;
SET @mba_invalid_version_sql := IF(
  @mba_version_column_count=1,
  'SELECT COUNT(*) INTO @mba_invalid_version_rows FROM eb_user WHERE balance_version<=0',
  'SELECT 0 INTO @mba_invalid_version_rows'
);
PREPARE mba_invalid_version_stmt FROM @mba_invalid_version_sql;
EXECUTE mba_invalid_version_stmt;
DEALLOCATE PREPARE mba_invalid_version_stmt;

SELECT COUNT(*) INTO @mba_invariant_mismatch_count
FROM eb_user
WHERE now_money<0 OR ben_money<0 OR give_money<0
  OR NOT (now_money <=> ben_money + give_money);

SET @mba_trigger_partial_valid := IF(
  @mba_named_trigger_count=0 AND @mba_before_update_triggers=0,
  1,
  IF(@mba_named_trigger_count=1 AND @mba_named_trigger_exact=1 AND @mba_before_update_triggers=1,1,0)
);

SET @mba_failures := @mba_failures
  + IF(@mba_upgrade_log_exists=1,0,1)
  + IF(@mba_registered=0,0,1)
  + IF(@mba_dependency_tables=2,0,1)
  + IF(@mba_dependency_columns=21,0,1)
  + IF(@mba_money_columns_exact=7,0,1)
  + IF(@mba_version_column_exact=@mba_version_column_count,0,1)
  + IF(@mba_ledger_target_column_exact=@mba_ledger_target_column_count,0,1)
  + IF(@mba_idempotency_unique_exact IN (0,1),0,1)
  + IF(@mba_duplicate_idempotency=0,0,1)
  + IF(@mba_invalid_version_rows=0,0,1)
  + IF(@mba_trigger_partial_valid=1,0,1);

SELECT
  @mba_db AS db_name,
  VERSION() AS mysql_version,
  @mba_registered AS already_registered,
  @mba_dependency_tables AS dependency_table_count,
  @mba_dependency_columns AS dependency_column_count,
  @mba_version_column_count AS existing_version_column_count,
  @mba_ledger_target_column_count AS existing_ledger_target_column_count,
  @mba_idempotency_unique_exact AS existing_idempotency_unique_count,
  @mba_before_update_triggers AS before_update_trigger_count,
  @mba_named_trigger_exact AS exact_balance_trigger_count,
  @mba_invariant_mismatch_count AS balance_reconciliation_required_count,
  @mba_failures AS precheck_failure_count;

SET @mba_finish_sql := IF(
  @mba_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_CASHIER_V3_MEMBER_BALANCE_PRECHECK_FAILED'
);
PREPARE mba_finish_stmt FROM @mba_finish_sql;
EXECUTE mba_finish_stmt;
DEALLOCATE PREPARE mba_finish_stmt;
