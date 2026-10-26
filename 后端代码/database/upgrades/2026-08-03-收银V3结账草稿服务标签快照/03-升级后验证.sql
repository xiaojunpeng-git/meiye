-- upgrade_key: 20260803-004-cashier-v3-checkout-draft-service-tags-v1
-- Read-only exact schema and row-value verification. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @cdst_db := DATABASE();
SET @cdst_failures := 0;

SELECT COUNT(*) INTO @cdst_tag_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cdst_db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft'
  AND ((COLUMN_NAME='service_object' AND COLUMN_TYPE='varchar(16)' AND IS_NULLABLE='NO'
        AND COLUMN_DEFAULT='' AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin')
    OR (COLUMN_NAME='is_experience' AND COLUMN_TYPE='tinyint(3) unsigned' AND IS_NULLABLE='NO'
        AND COLUMN_DEFAULT='0'));

SELECT COUNT(*) INTO @cdst_total_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@cdst_db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft';

SELECT COUNT(*) INTO @cdst_invalid_rows
FROM eb_cashier_v3_checkout_line_draft
WHERE service_object NOT IN ('','self','friend')
   OR is_experience NOT IN (0,1)
   OR (source_type<>'project' AND (service_object<>'' OR is_experience<>0));

SET @cdst_failures := @cdst_failures
  + IF(@cdst_tag_columns=2,0,1)
  + IF(@cdst_total_columns=34,0,1)
  + IF(@cdst_invalid_rows=0,0,1);

SELECT @cdst_tag_columns AS exact_service_tag_column_count,
  @cdst_total_columns AS checkout_line_draft_column_count,
  @cdst_invalid_rows AS invalid_service_tag_row_count,
  @cdst_failures AS postcheck_failure_count;

SET @cdst_finish_sql := IF(
  @cdst_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_CASHIER_V3_CHECKOUT_DRAFT_SERVICE_TAGS_POSTCHECK_FAILED'
);
PREPARE cdst_finish_stmt FROM @cdst_finish_sql;
EXECUTE cdst_finish_stmt;
DEALLOCATE PREPARE cdst_finish_stmt;
