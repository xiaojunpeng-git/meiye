-- upgrade_key: 20260814-002-cashier-v3-labor-performance-fee-split
SET @db := DATABASE();
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME IN ('eb_cashier_v3_performance_fact','eb_cashier_v3_entitlement_service_fact');
SELECT CASE WHEN COUNT(*)=2 THEN 'PRECHECK_OK' ELSE 'STOP_MISSING_REQUIRED_TABLE' END AS precheck_result
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME IN ('eb_cashier_v3_performance_fact','eb_cashier_v3_entitlement_service_fact');
