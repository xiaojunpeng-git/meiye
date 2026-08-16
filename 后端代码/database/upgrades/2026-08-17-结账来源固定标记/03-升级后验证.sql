-- upgrade_key: 20260817-004-cashier-v3-source-fixed-flag
SET NAMES utf8mb4;

SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME='eb_cashier_v3_business_source'
  AND COLUMN_NAME='is_fixed';

SELECT id,parent_id,name,status,is_fixed,version
FROM eb_cashier_v3_business_source
ORDER BY parent_id,sort,id;

SELECT upgrade_key,executed_at,result_note
FROM eb_database_upgrade_log
WHERE upgrade_key='20260817-004-cashier-v3-source-fixed-flag';
