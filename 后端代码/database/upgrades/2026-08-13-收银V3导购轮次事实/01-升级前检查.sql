-- upgrade_key: 20260813-001-cashier-v3-guide-round-fact
-- Read-only precheck. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @gr_db := DATABASE();
SET @gr_authority_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=@gr_db
    AND TABLE_NAME IN ('eb_cashier_v3_checkout_request','eb_cashier_v3_business_event','eb_employee')
    AND ENGINE='InnoDB'
);
SELECT @gr_authority_tables AS required_authority_tables;
SELECT COUNT(*) AS existing_target_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@gr_db AND TABLE_NAME='eb_cashier_v3_customer_guide_round_fact';
SELECT CASE WHEN @gr_authority_tables = 3 THEN 'PRECHECK_OK' ELSE 'STOP_MISSING_AUTHORITY_TABLE' END AS precheck_result;
