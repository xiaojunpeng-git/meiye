-- upgrade_key: 20260817-004-cashier-v3-source-fixed-flag
SET NAMES utf8mb4;

SELECT DATABASE() AS target_database;

SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_business_source'
  AND COLUMN_NAME='is_fixed';

SELECT id,parent_id,name,status,sort,version
FROM eb_cashier_v3_business_source
ORDER BY parent_id,sort,id;
