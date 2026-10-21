-- Read-only verification for R22 platform member store transfer audit.
SET NAMES utf8mb4;

SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_user_belong_store'
  AND COLUMN_NAME IN ('from_store_id','admin_id','reason','from_store_name','to_store_name','operator_name')
ORDER BY ORDINAL_POSITION;

SELECT
  SUM(CASE WHEN COLUMN_NAME='from_store_id' THEN 1 ELSE 0 END) AS has_from_store_id,
  SUM(CASE WHEN COLUMN_NAME='admin_id' THEN 1 ELSE 0 END) AS has_admin_id,
  SUM(CASE WHEN COLUMN_NAME='reason' THEN 1 ELSE 0 END) AS has_reason
  ,SUM(CASE WHEN COLUMN_NAME='from_store_name' THEN 1 ELSE 0 END) AS has_from_store_name
  ,SUM(CASE WHEN COLUMN_NAME='to_store_name' THEN 1 ELSE 0 END) AS has_to_store_name
  ,SUM(CASE WHEN COLUMN_NAME='operator_name' THEN 1 ELSE 0 END) AS has_operator_name
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_user_belong_store';
