-- upgrade_key: 20261102-001-reservation-service-duration
-- 服务时长独立于预约占用时段；旧数据按原时段一次性赋值，重复执行不覆盖人工修改。
SET NAMES utf8mb4;
SET @duration_db := DATABASE();
SET @duration_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@duration_db AND TABLE_NAME='eb_cashier_v3_reservation' AND COLUMN_NAME='service_countdown_duration_minutes')=0,
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `service_countdown_duration_minutes` int(11) unsigned NOT NULL DEFAULT 0 AFTER `appointment_end_at`',
  'SELECT 1');
PREPARE duration_stmt FROM @duration_sql; EXECUTE duration_stmt; DEALLOCATE PREPARE duration_stmt;

UPDATE `eb_cashier_v3_reservation`
SET `service_countdown_duration_minutes` = CASE
  WHEN `appointment_end_at` > `appointment_start_at`
    THEN GREATEST(1, CEIL((`appointment_end_at` - `appointment_start_at`) / 60))
  ELSE 1
END
WHERE `service_countdown_duration_minutes` = 0;

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT '20261102-001-reservation-service-duration', '预约服务计时时长及旧数据赋值',
  'database/upgrades/2026-11-02-预约服务计时时长/02-正式升级.sql', '', 'LOCAL', NOW(), 'Codex',
  '旧预约按原预约时段赋值；0值只作迁移兼容，不覆盖已经修改的时长'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `eb_database_upgrade_log` WHERE `upgrade_key`='20261102-001-reservation-service-duration');
