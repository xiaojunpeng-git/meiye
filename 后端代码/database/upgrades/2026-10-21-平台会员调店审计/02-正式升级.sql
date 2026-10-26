-- upgrade_key: 20261021-001-platform-member-store-transfer-audit
-- MySQL 5.7 compatible, replay-safe, schema-only. Existing history rows remain unchanged.
SET NAMES utf8mb4;
SET @r22_db := DATABASE();

SET @r22_has_from_store := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@r22_db AND TABLE_NAME='eb_user_belong_store' AND COLUMN_NAME='from_store_id'
);
SET @r22_sql := IF(@r22_has_from_store=0,
  'ALTER TABLE `eb_user_belong_store` ADD COLUMN `from_store_id` int(10) unsigned NOT NULL DEFAULT ''0'' COMMENT ''变更前归属门店ID'' AFTER `store_id`',
  'SELECT ''skip eb_user_belong_store.from_store_id''');
PREPARE r22_stmt FROM @r22_sql; EXECUTE r22_stmt; DEALLOCATE PREPARE r22_stmt;

SET @r22_has_admin := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@r22_db AND TABLE_NAME='eb_user_belong_store' AND COLUMN_NAME='admin_id'
);
SET @r22_sql := IF(@r22_has_admin=0,
  'ALTER TABLE `eb_user_belong_store` ADD COLUMN `admin_id` int(10) unsigned NOT NULL DEFAULT ''0'' COMMENT ''平台操作管理员ID'' AFTER `staff_id`',
  'SELECT ''skip eb_user_belong_store.admin_id''');
PREPARE r22_stmt FROM @r22_sql; EXECUTE r22_stmt; DEALLOCATE PREPARE r22_stmt;

SET @r22_has_reason := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@r22_db AND TABLE_NAME='eb_user_belong_store' AND COLUMN_NAME='reason'
);
SET @r22_sql := IF(@r22_has_reason=0,
  'ALTER TABLE `eb_user_belong_store` ADD COLUMN `reason` varchar(255) NOT NULL DEFAULT '''' COMMENT ''会员调店原因'' AFTER `admin_id`',
  'SELECT ''skip eb_user_belong_store.reason''');
PREPARE r22_stmt FROM @r22_sql; EXECUTE r22_stmt; DEALLOCATE PREPARE r22_stmt;

SET @r22_has_from_store_name := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@r22_db AND TABLE_NAME='eb_user_belong_store' AND COLUMN_NAME='from_store_name'
);
SET @r22_sql := IF(@r22_has_from_store_name=0,
  'ALTER TABLE `eb_user_belong_store` ADD COLUMN `from_store_name` varchar(100) NOT NULL DEFAULT '''' COMMENT ''变更前门店名称快照'' AFTER `reason`',
  'SELECT ''skip eb_user_belong_store.from_store_name''');
PREPARE r22_stmt FROM @r22_sql; EXECUTE r22_stmt; DEALLOCATE PREPARE r22_stmt;

SET @r22_has_to_store_name := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@r22_db AND TABLE_NAME='eb_user_belong_store' AND COLUMN_NAME='to_store_name'
);
SET @r22_sql := IF(@r22_has_to_store_name=0,
  'ALTER TABLE `eb_user_belong_store` ADD COLUMN `to_store_name` varchar(100) NOT NULL DEFAULT '''' COMMENT ''变更后门店名称快照'' AFTER `from_store_name`',
  'SELECT ''skip eb_user_belong_store.to_store_name''');
PREPARE r22_stmt FROM @r22_sql; EXECUTE r22_stmt; DEALLOCATE PREPARE r22_stmt;

SET @r22_has_operator_name := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@r22_db AND TABLE_NAME='eb_user_belong_store' AND COLUMN_NAME='operator_name'
);
SET @r22_sql := IF(@r22_has_operator_name=0,
  'ALTER TABLE `eb_user_belong_store` ADD COLUMN `operator_name` varchar(100) NOT NULL DEFAULT '''' COMMENT ''操作人名称快照'' AFTER `to_store_name`',
  'SELECT ''skip eb_user_belong_store.operator_name''');
PREPARE r22_stmt FROM @r22_sql; EXECUTE r22_stmt; DEALLOCATE PREPARE r22_stmt;

SELECT 'APPLY_OK' AS apply_result;
