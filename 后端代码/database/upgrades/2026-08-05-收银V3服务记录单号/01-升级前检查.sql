-- upgrade_key: 20260805-001-cashier-v3-service-document-no
-- Service facts and the unified document allocator must already exist.
SET NAMES utf8mb4;
SET @service_no_db := DATABASE();

SELECT COUNT(*) INTO @service_no_fact
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@service_no_db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @service_no_document_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@service_no_db
  AND TABLE_NAME IN ('eb_cashier_v3_business_document_sequence','eb_cashier_v3_business_document_no')
  AND ENGINE='InnoDB';

SELECT IF(@service_no_fact=1 AND @service_no_document_tables=2,
  'PRECHECK_OK', 'STOP_CASHIER_V3_SERVICE_DOCUMENT_NO_PRECHECK_FAILED') AS precheck_result;
