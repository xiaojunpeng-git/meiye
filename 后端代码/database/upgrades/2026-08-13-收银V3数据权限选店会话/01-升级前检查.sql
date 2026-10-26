SET @db = DATABASE();
SELECT 'cashier_v3_delegated_store_session_precheck' AS check_name,
       (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_store_session') AS table_exists;
