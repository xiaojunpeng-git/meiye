-- upgrade_key: 20260803-005-cashier-v3-business-document-numbers
SET NAMES utf8mb4;

SELECT COUNT(*) INTO @cashier_document_tables_ok
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('eb_cashier_v3_business_document_sequence','eb_cashier_v3_business_document_no')
  AND engine = 'InnoDB';
SELECT COUNT(*) INTO @cashier_document_gift_no_ok
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'eb_cashier_v3_recharge_gift_authority'
  AND column_name = 'gift_no'
  AND is_nullable = 'YES';
SELECT IF(@cashier_document_tables_ok = 2 AND @cashier_document_gift_no_ok = 1, 'POSTCHECK_OK', 'STOP_CASHIER_DOCUMENT_NUMBER_POSTCHECK_FAILED') AS postcheck_result;
