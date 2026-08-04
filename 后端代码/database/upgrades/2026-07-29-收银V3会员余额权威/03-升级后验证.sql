-- upgrade_key: 20260729-009-cashier-v3-member-balance-authority
-- Read-only verification. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @mba_db := DATABASE();
SET @mba_failures := 0;

SELECT COUNT(*) INTO @mba_exact_columns
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

SELECT COUNT(*) INTO @mba_unique_exact
FROM (
  SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money'
  GROUP BY INDEX_NAME,NON_UNIQUE
  HAVING NON_UNIQUE=0 AND index_columns='idempotency_key'
    AND SUM(IF(SUB_PART IS NULL,0,1))=0
) mba_unique;

SELECT COUNT(*) INTO @mba_trigger_exact
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

SELECT COUNT(*) INTO @mba_other_before_update_triggers
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA=@mba_db AND EVENT_OBJECT_TABLE='eb_user'
  AND ACTION_TIMING='BEFORE' AND EVENT_MANIPULATION='UPDATE'
  AND TRIGGER_NAME<>'eb_user_balance_version_bu';

SELECT COUNT(*) INTO @mba_invalid_version_rows
FROM eb_user WHERE balance_version<=0;

SELECT COUNT(*) INTO @mba_invariant_mismatch_count
FROM eb_user
WHERE now_money<0 OR ben_money<0 OR give_money<0
  OR NOT (now_money <=> ben_money + give_money);

SELECT COUNT(*) INTO @mba_duplicate_idempotency
FROM (
  SELECT idempotency_key
  FROM eb_user_money
  WHERE idempotency_key IS NOT NULL
  GROUP BY idempotency_key
  HAVING COUNT(*)>1
) duplicate_keys;

SET @mba_failures := @mba_failures
  + IF(@mba_exact_columns=5,0,1)
  + IF(@mba_unique_exact=1,0,1)
  + IF(@mba_trigger_exact=1,0,1)
  + IF(@mba_other_before_update_triggers=0,0,1)
  + IF(@mba_invalid_version_rows=0,0,1)
  + IF(@mba_duplicate_idempotency=0,0,1);

SELECT
  @mba_exact_columns AS exact_authority_column_count,
  @mba_unique_exact AS idempotency_unique_count,
  @mba_trigger_exact AS exact_balance_trigger_count,
  @mba_invalid_version_rows AS invalid_balance_version_count,
  @mba_invariant_mismatch_count AS balance_reconciliation_required_count,
  IF(@mba_invariant_mismatch_count=0,'READY','RECONCILIATION_REQUIRED') AS v3_balance_data_status,
  @mba_failures AS verification_failure_count;

SELECT
  uid AS member_id,
  now_money,
  ben_money,
  give_money,
  balance_version,
  '核对原业务流水后以可审计调整修复，禁止直接覆盖' AS required_action
FROM eb_user
WHERE now_money<0 OR ben_money<0 OR give_money<0
  OR NOT (now_money <=> ben_money + give_money)
ORDER BY uid;

SET @mba_finish_sql := IF(
  @mba_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_MEMBER_BALANCE_POSTCHECK_FAILED'
);
PREPARE mba_finish_stmt FROM @mba_finish_sql;
EXECUTE mba_finish_stmt;
DEALLOCATE PREPARE mba_finish_stmt;
