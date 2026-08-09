-- upgrade_key: 20260805-001-cashier-v3-service-document-no
SET NAMES utf8mb4;
SET @service_no_db := DATABASE();

SELECT COUNT(*) INTO @service_no_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@service_no_db
  AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact'
  AND COLUMN_NAME='service_record_no'
  AND COLUMN_TYPE='varchar(32)';

SELECT COUNT(*) INTO @service_no_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@service_no_db
  AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact'
  AND INDEX_NAME='uk_tenant_service_record_no'
  AND NON_UNIQUE=0;

SELECT COUNT(*) INTO @service_no_bad_rows
FROM eb_cashier_v3_entitlement_service_fact
WHERE service_record_no IS NOT NULL
  AND (service_record_no='' OR service_record_no NOT REGEXP '^FW[0-9]{9}$');

SELECT IF(@service_no_column=1 AND @service_no_index=2 AND @service_no_bad_rows=0,
  'POSTCHECK_OK', 'STOP_CASHIER_V3_SERVICE_DOCUMENT_NO_POSTCHECK_FAILED') AS postcheck_result;
