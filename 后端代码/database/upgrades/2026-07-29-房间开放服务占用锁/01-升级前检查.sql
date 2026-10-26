-- upgrade_key: 20260729-019-room-open-service-guard
-- Read-only precheck. No legacy room/reservation data is migrated in this task.
SET NAMES utf8mb4;
SET @db := DATABASE();

SELECT COUNT(*) AS existing_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME='eb_cashier_v3_room_open_service_guard';

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME='eb_cashier_v3_room_open_service_guard';

SELECT 'PRECHECK_OK_REVIEW_EXISTING_TABLE_BEFORE_APPLY' AS precheck_result;
