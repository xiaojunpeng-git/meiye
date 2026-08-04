-- upgrade_key: 20260803-005-cashier-v3-business-document-numbers
-- No historical order number is backfilled or rewritten by this upgrade.
SET NAMES utf8mb4;

SET @cashier_document_db := DATABASE();
SELECT COUNT(*) INTO @cashier_document_gift_authority_ok
FROM information_schema.tables
WHERE table_schema = @cashier_document_db
  AND table_name = 'eb_cashier_v3_recharge_gift_authority'
  AND engine = 'InnoDB';
SELECT IF(@cashier_document_gift_authority_ok = 1, 'PRECHECK_OK', 'STOP_CASHIER_DOCUMENT_NUMBER_PRECHECK_FAILED') AS precheck_result;
