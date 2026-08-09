-- upgrade_key: 20260729-019-room-open-service-guard
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) AS table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME='eb_cashier_v3_room_open_service_guard'
  AND ENGINE='InnoDB';

SELECT COUNT(*) AS column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME='eb_cashier_v3_room_open_service_guard';

SELECT INDEX_NAME, NON_UNIQUE,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS columns_in_order
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME='eb_cashier_v3_room_open_service_guard'
GROUP BY INDEX_NAME, NON_UNIQUE
ORDER BY INDEX_NAME;

SELECT COUNT(*) AS invalid_rows
FROM `eb_cashier_v3_room_open_service_guard`
WHERE tenant_id=''
   OR store_id=0
   OR room_id=0
   OR slot_key<>CONCAT('open-service:',room_id)
   OR current_version=0
   OR occupation_status NOT IN ('FREE','OCCUPIED')
   OR (occupation_status='FREE' AND (owner_kind<>'' OR owner_id<>''))
   OR (occupation_status='OCCUPIED' AND (owner_kind='' OR owner_id=''));

SELECT 'POSTCHECK_REQUIRES table_count=1,column_count=16,invalid_rows=0' AS postcheck_result;
