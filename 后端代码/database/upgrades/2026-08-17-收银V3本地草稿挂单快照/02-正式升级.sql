-- upgrade_key: 20260817-021-cashier-v3-local-draft-hang-v1
-- MySQL 5.6 compatible and replay-safe.
SET NAMES utf8mb4;

SET @column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='local_draft_snapshot_json');
SET @sql := IF(@column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `local_draft_snapshot_json` longtext NULL AFTER `room_guard_fingerprint`',
 'SELECT ''local_draft_snapshot_json already exists'' AS apply_note');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'APPLY_OK' AS apply_result;
