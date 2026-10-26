-- upgrade_key: 20260729-006-c3-generic-service-order-line
-- Read-only. MySQL 5.6.51 compatible.
SET NAMES utf8mb4;
SET @c3gl_db := DATABASE();
SET @c3gl_failures := 0;

SELECT COUNT(*) INTO @c3gl_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line'
  AND COLUMN_NAME IN (
    'source_type','source_id','source_version_snapshot','hang_line_id',
    'service_quantity','authority_fingerprint'
  );
SELECT COUNT(*) INTO @c3gl_indexes
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c3gl_db AND TABLE_NAME='eb_cashier_v3_service_order_line'
  AND INDEX_NAME IN ('idx_tenant_line_source','idx_tenant_entitlement_source');
SELECT COUNT(*) INTO @c3gl_bad_rows
FROM eb_cashier_v3_service_order_line
WHERE source_type NOT IN ('ENTITLEMENT','SALE_PROJECT')
  OR (source_type='ENTITLEMENT' AND (
    entitlement_source_detail_id=0 OR service_quantity<>0
    OR source_id<>0 OR source_version_snapshot<>0 OR hang_line_id<>0 OR authority_fingerprint<>''
    OR (status='ACTIVE' AND occupied_times=0) OR (status='RELEASED' AND occupied_times<>0)
  ))
  OR (source_type='SALE_PROJECT' AND (
    source_id=0 OR source_version_snapshot=0 OR hang_line_id=0 OR service_quantity=0
    OR authority_fingerprint NOT REGEXP '^[a-f0-9]{64}$'
    OR entitlement_source_detail_id<>0 OR entitlement_instance_id<>0 OR occupied_times<>0
  ));

SET @c3gl_failures := @c3gl_failures
  + IF(@c3gl_columns=6,0,1)
  + IF(@c3gl_indexes>=2,0,1)
  + IF(@c3gl_bad_rows=0,0,1);
SELECT @c3gl_columns AS required_column_count,
  @c3gl_indexes AS source_index_part_count,
  @c3gl_bad_rows AS invalid_line_count,
  @c3gl_failures AS verification_failure_count;
SET @c3gl_finish_sql := IF(
  @c3gl_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_C3_GENERIC_LINE_POSTCHECK_FAILED'
);
PREPARE c3gl_finish_stmt FROM @c3gl_finish_sql;
EXECUTE c3gl_finish_stmt;
DEALLOCATE PREPARE c3gl_finish_stmt;
