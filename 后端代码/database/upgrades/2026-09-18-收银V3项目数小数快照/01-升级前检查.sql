-- upgrade_key: 20260918-001-cashier-v3-project-count-decimal
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) AS performance_fact_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_performance_fact';
