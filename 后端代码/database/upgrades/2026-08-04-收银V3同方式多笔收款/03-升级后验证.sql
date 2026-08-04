-- upgrade_key: 20260804-001-cashier-v3-repeat-payment-method-drafts
-- Read-only postcheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @rpm_db := DATABASE();
SET @rpm_failures := 0;

SELECT COUNT(*) INTO @rpm_legacy_index_remaining
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@rpm_db AND TABLE_NAME='eb_cashier_v3_checkout_payment_draft'
  AND INDEX_NAME='uk_request_method';

SELECT COUNT(*) INTO @rpm_authority_index
FROM (
  SELECT INDEX_NAME, MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@rpm_db AND TABLE_NAME='eb_cashier_v3_checkout_payment_draft'
    AND INDEX_NAME='uk_request_payment_authority'
  GROUP BY INDEX_NAME
  HAVING non_unique=0 AND index_columns='request_id,payment_authority_key'
) rpm_authority_indexes;

SELECT COUNT(*) INTO @rpm_payment_draft_index
FROM (
  SELECT INDEX_NAME, MAX(NON_UNIQUE) AS non_unique,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@rpm_db AND TABLE_NAME='eb_cashier_v3_checkout_payment_draft'
    AND INDEX_NAME='uk_payment_draft_id'
  GROUP BY INDEX_NAME
  HAVING non_unique=0 AND index_columns='payment_draft_id'
) rpm_payment_draft_indexes;

SELECT COUNT(*) INTO @rpm_duplicate_authorities
FROM (
  SELECT request_id,payment_authority_key
  FROM eb_cashier_v3_checkout_payment_draft
  GROUP BY request_id,payment_authority_key
  HAVING COUNT(*)>1
) rpm_duplicate_authority_rows;

SET @rpm_failures := @rpm_failures
  + IF(@rpm_legacy_index_remaining=0,0,1)
  + IF(@rpm_authority_index=1,0,1)
  + IF(@rpm_payment_draft_index=1,0,1)
  + IF(@rpm_duplicate_authorities=0,0,1);

SELECT @rpm_legacy_index_remaining AS legacy_request_method_index_count,
  @rpm_authority_index AS request_authority_index_count,
  @rpm_payment_draft_index AS payment_draft_id_index_count,
  @rpm_duplicate_authorities AS duplicate_request_authority_count,
  @rpm_failures AS postcheck_failure_count;

SET @rpm_finish_sql := IF(
  @rpm_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_REPEAT_PAYMENT_METHOD_POSTCHECK_FAILED'
);
PREPARE rpm_finish_stmt FROM @rpm_finish_sql;
EXECUTE rpm_finish_stmt;
DEALLOCATE PREPARE rpm_finish_stmt;
