-- upgrade_key: 20260924-001-cashier-v3-checkout-reservation-service-link-v1
SET NAMES utf8mb4;

SELECT COUNT(*) AS table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_reservation_checkout_service_link';

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_reservation_checkout_service_link'
GROUP BY INDEX_NAME
ORDER BY INDEX_NAME;

SELECT COUNT(*) AS invalid_link_count
FROM `eb_cashier_v3_reservation_checkout_service_link`
WHERE reservation_id=0 OR sales_order_id='' OR service_fact_id=''
   OR source_line_id='' OR project_id=0 OR quantity=0
   OR immutable_fingerprint='' OR business_date='0000-00-00';

SELECT COUNT(*) AS upgrade_log_count
FROM `eb_database_upgrade_log`
WHERE `upgrade_key`='20260924-001-cashier-v3-checkout-reservation-service-link-v1';
