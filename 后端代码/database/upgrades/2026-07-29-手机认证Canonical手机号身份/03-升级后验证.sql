-- upgrade_key: 20260729-013-mobile-auth-canonical-identity
-- Read-only verification. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @maci_db := DATABASE();
SET @maci_failures := 0;

SELECT COUNT(*) INTO @maci_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME IN ('eb_user_phone_identity','eb_user_phone_identity_audit')
  AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @maci_identity_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_user_phone_identity'
  AND ((COLUMN_NAME='id' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%' AND EXTRA LIKE '%auto_increment%')
    OR (COLUMN_NAME='phone_digest' AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH=64 AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO')
    OR (COLUMN_NAME='bound_uid' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='YES')
    OR (COLUMN_NAME='state' AND DATA_TYPE='varchar' AND CHARACTER_MAXIMUM_LENGTH=32 AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='RESERVED')
    OR (COLUMN_NAME='identity_version' AND DATA_TYPE='bigint' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='1')
    OR (COLUMN_NAME IN ('bound_at','released_at','created_at','updated_at') AND DATA_TYPE='int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='0'));

SELECT COUNT(*) INTO @maci_audit_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME='eb_user_phone_identity_audit'
  AND COLUMN_NAME IN ('id','identity_id','phone_digest','before_uid','after_uid','before_state','after_state','before_version','after_version','action','operator_type','operator_id','source','request_id','occurred_at','recorded_at');

SELECT COUNT(*) INTO @maci_indexes
FROM (
  SELECT TABLE_NAME,INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@maci_db AND TABLE_NAME IN ('eb_user_phone_identity','eb_user_phone_identity_audit')
  GROUP BY TABLE_NAME,INDEX_NAME
  HAVING CONCAT(TABLE_NAME,'|',INDEX_NAME,'|',non_unique,'|',index_columns) IN (
    'eb_user_phone_identity|PRIMARY|0|id',
    'eb_user_phone_identity|uk_phone_digest|0|phone_digest',
    'eb_user_phone_identity|uk_bound_uid|0|bound_uid',
    'eb_user_phone_identity|idx_state_updated|1|state,updated_at',
    'eb_user_phone_identity_audit|PRIMARY|0|id',
    'eb_user_phone_identity_audit|idx_identity_time|1|identity_id,recorded_at',
    'eb_user_phone_identity_audit|idx_request|1|request_id'
  )
) maci_indexes;

SELECT COUNT(*) INTO @maci_invalid_identity_rows
FROM eb_user_phone_identity
WHERE phone_digest='' OR identity_version<=0
  OR state NOT IN ('RESERVED','BOUND','RELEASED','LEGACY_CONFLICT','DISABLED')
  OR (state='BOUND' AND bound_uid IS NULL)
  OR (state<>'BOUND' AND bound_uid IS NOT NULL);

SELECT COUNT(*) INTO @maci_cancelled_bound_rows
FROM eb_user_phone_identity i
INNER JOIN eb_user u ON u.uid=i.bound_uid
WHERE i.state='BOUND' AND u.is_del=1;

SELECT COUNT(*) INTO @maci_invalid_audit_rows
FROM eb_user_phone_identity_audit
WHERE action NOT IN ('RESERVE','BIND','RELEASE','REASSIGN','CONFLICT','DISABLE','RELEASE_ON_MEMBER_CANCEL','REASSIGN_AFTER_MEMBER_CANCEL');

SELECT COUNT(*) INTO @maci_unmapped_unique_live
FROM (
  SELECT u.phone FROM eb_user u
  INNER JOIN (
    SELECT phone FROM eb_user
    WHERE phone REGEXP '^1[3-9][0-9]{9}$'
    GROUP BY phone HAVING COUNT(*)=1
  ) globally_unique ON globally_unique.phone=u.phone
  WHERE u.is_del=0
) unique_live
LEFT JOIN eb_user_phone_identity i ON i.phone_digest=SHA2(unique_live.phone,256)
WHERE i.id IS NULL OR i.state<>'BOUND';

SELECT id,phone_digest,state,bound_uid,identity_version
FROM eb_user_phone_identity
WHERE phone_digest='' OR identity_version<=0
  OR state NOT IN ('RESERVED','BOUND','RELEASED','LEGACY_CONFLICT','DISABLED')
  OR (state='BOUND' AND bound_uid IS NULL)
  OR (state<>'BOUND' AND bound_uid IS NOT NULL);

SELECT SHA2(unique_live.phone,256) AS missing_phone_digest, i.id AS identity_id, i.state AS identity_state
FROM (
  SELECT u.phone FROM eb_user u
  INNER JOIN (
    SELECT phone FROM eb_user
    WHERE phone REGEXP '^1[3-9][0-9]{9}$'
    GROUP BY phone HAVING COUNT(*)=1
  ) globally_unique ON globally_unique.phone=u.phone
  WHERE u.is_del=0
) unique_live
LEFT JOIN eb_user_phone_identity i ON i.phone_digest=SHA2(unique_live.phone,256)
WHERE i.id IS NULL OR i.state<>'BOUND';

SET @maci_failures := @maci_failures
  + IF(@maci_tables=2,0,1)
  + IF(@maci_identity_columns=9,0,1)
  + IF(@maci_audit_columns=16,0,1)
  + IF(@maci_indexes=7,0,1)
  + IF(@maci_invalid_identity_rows=0,0,1)
  + IF(@maci_cancelled_bound_rows=0,0,1)
  + IF(@maci_invalid_audit_rows=0,0,1)
  + IF(@maci_unmapped_unique_live=0,0,1);

SELECT @maci_tables AS exact_table_count,
  @maci_identity_columns AS exact_identity_column_count,
  @maci_audit_columns AS exact_audit_column_count,
  @maci_indexes AS exact_index_count,
  @maci_invalid_identity_rows AS invalid_identity_row_count,
  @maci_cancelled_bound_rows AS cancelled_bound_row_count,
  @maci_unmapped_unique_live AS unmapped_unique_live_phone_count,
  @maci_failures AS verification_failure_count;

SET @maci_finish_sql := IF(@maci_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_MOBILE_AUTH_CANONICAL_IDENTITY_POSTCHECK_FAILED');
PREPARE maci_finish_stmt FROM @maci_finish_sql;
EXECUTE maci_finish_stmt;
DEALLOCATE PREPARE maci_finish_stmt;
