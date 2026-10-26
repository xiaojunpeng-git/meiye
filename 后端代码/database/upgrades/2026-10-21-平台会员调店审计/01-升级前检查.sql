-- Read-only preflight for R22 platform member store transfer audit.
SET NAMES utf8mb4;

SELECT DATABASE() AS current_database;
SELECT COUNT(*) AS user_belong_store_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_user_belong_store';

SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_user_belong_store'
  AND COLUMN_NAME IN ('from_store_id','admin_id','reason','from_store_name','to_store_name','operator_name')
ORDER BY ORDINAL_POSITION;
