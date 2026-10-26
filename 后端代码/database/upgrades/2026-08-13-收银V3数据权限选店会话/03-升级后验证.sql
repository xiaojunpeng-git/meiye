SET @db = DATABASE();
SELECT COUNT(*) AS table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_store_session';
SELECT COUNT(*) AS index_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_store_session'
  AND INDEX_NAME IN ('PRIMARY','uk_token_secret_hash','idx_employee_status','idx_store_status');
