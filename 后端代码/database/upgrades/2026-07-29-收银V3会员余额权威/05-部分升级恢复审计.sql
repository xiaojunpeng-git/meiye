-- upgrade_key: 20260729-009-cashier-v3-member-balance-authority
-- Read-only partial-upgrade audit. It never drops or rewrites schema.
SET NAMES utf8mb4;
SET @mba_db := DATABASE();
SET @mba_failures := 0;

SELECT COUNT(*) INTO @mba_target_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND (
  (TABLE_NAME='eb_user' AND COLUMN_NAME='balance_version')
  OR
  (TABLE_NAME='eb_user_money' AND COLUMN_NAME IN (
    'ben_change_amount','give_change_amount','idempotency_key','idempotency_fingerprint'
  ))
);

SELECT COUNT(*) INTO @mba_target_column_exact
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND (
  (TABLE_NAME='eb_user' AND COLUMN_NAME='balance_version'
    AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%'
    AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1')
  OR
  (TABLE_NAME='eb_user_money' AND COLUMN_NAME IN ('ben_change_amount','give_change_amount')
    AND DATA_TYPE='decimal' AND NUMERIC_PRECISION=12 AND NUMERIC_SCALE=2
    AND IS_NULLABLE='YES' AND COLUMN_DEFAULT IS NULL)
  OR
  (TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_key'
    AND DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH=128 AND IS_NULLABLE='YES'
    AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
  OR
  (TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_fingerprint'
    AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH=64 AND IS_NULLABLE='YES'
    AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
);

SELECT COUNT(*) INTO @mba_trigger_count
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=@mba_db AND TRIGGER_NAME='eb_user_balance_version_bu';

SELECT COUNT(*) INTO @mba_trigger_exact
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=@mba_db AND TRIGGER_NAME='eb_user_balance_version_bu'
  AND EVENT_OBJECT_TABLE='eb_user' AND ACTION_TIMING='BEFORE' AND EVENT_MANIPULATION='UPDATE'
  AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(ACTION_STATEMENT,'`',''),' ',''),CHAR(9),''),CHAR(10),''))
      LIKE '%new.balance_version=old.balance_version+1%'
  AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(ACTION_STATEMENT,'`',''),' ',''),CHAR(9),''),CHAR(10),''))
      LIKE '%new.balance_version=old.balance_version%';

SELECT COUNT(*) INTO @mba_other_before_update_triggers
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=@mba_db AND EVENT_OBJECT_TABLE='eb_user'
  AND ACTION_TIMING='BEFORE' AND EVENT_MANIPULATION='UPDATE'
  AND TRIGGER_NAME<>'eb_user_balance_version_bu';

SELECT COUNT(*) INTO @mba_unique_exact
FROM (
  SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money'
  GROUP BY INDEX_NAME,NON_UNIQUE
  HAVING NON_UNIQUE=0 AND index_columns='idempotency_key'
    AND SUM(IF(SUB_PART IS NULL,0,1))=0
) mba_unique;

SET @mba_failures := @mba_failures
  + IF(@mba_target_column_count=@mba_target_column_exact,0,1)
  + IF(@mba_trigger_count=@mba_trigger_exact,0,1)
  + IF(@mba_other_before_update_triggers=0,0,1)
  + IF(@mba_unique_exact IN (0,1),0,1);

SELECT
  @mba_target_column_count AS present_target_column_count,
  @mba_target_column_exact AS exact_target_column_count,
  @mba_trigger_count AS present_target_trigger_count,
  @mba_trigger_exact AS exact_target_trigger_count,
  @mba_other_before_update_triggers AS conflicting_before_update_trigger_count,
  @mba_unique_exact AS exact_idempotency_unique_count,
  @mba_failures AS recovery_failure_count;

SET @mba_finish_sql := IF(
  @mba_failures=0,
  'SELECT ''PARTIAL_UPGRADE_RECOVERY_READY'' AS recovery_result',
  'SELECT * FROM STOP_MEMBER_BALANCE_PARTIAL_UPGRADE_HETEROGENEOUS'
);
PREPARE mba_finish_stmt FROM @mba_finish_sql;
EXECUTE mba_finish_stmt;
DEALLOCATE PREPARE mba_finish_stmt;
