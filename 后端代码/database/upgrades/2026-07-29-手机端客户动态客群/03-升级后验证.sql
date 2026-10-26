-- upgrade_key: 20260729-014-mobile-customer-audiences
SET NAMES utf8mb4;

SELECT table_name, table_type
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND table_name IN ('eb_mobile_customer_audience','eb_mobile_customer_audience_receipt')
ORDER BY table_name;

SELECT index_name, column_name, seq_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND table_name = 'eb_mobile_customer_audience_receipt'
  AND index_name = 'uk_owner_idempotency'
ORDER BY seq_in_index;

SELECT 'POSTCHECK_OK' AS postcheck_result;
