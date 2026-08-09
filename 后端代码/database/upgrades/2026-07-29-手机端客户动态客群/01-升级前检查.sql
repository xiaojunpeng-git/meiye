-- upgrade_key: 20260729-014-mobile-customer-audiences
-- MySQL 5.6 compatible. Do not execute this file as the upgrade itself.
SET NAMES utf8mb4;

SELECT
  CASE
    WHEN EXISTS (
      SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_mobile_customer_audience'
    )
    THEN 'EXISTING_TABLE_REQUIRES_SCHEMA_COMPARE'
    ELSE 'READY_TO_CREATE'
  END AS mobile_customer_audience_precheck;

SELECT 'PRECHECK_OK' AS precheck_result;
