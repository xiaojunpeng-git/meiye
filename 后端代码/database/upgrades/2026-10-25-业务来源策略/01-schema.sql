-- MySQL 5.7 compatible, instance-local and rerunnable. Existing source IDs,
-- orders and facts are unchanged. Migrated instances start enabled/manual.
SET @r57_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_member_commerce_settings' AND COLUMN_NAME='source_enabled'), 'SELECT 1', 'ALTER TABLE eb_member_commerce_settings ADD COLUMN source_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''客户来源选择开启开关''');
PREPARE r57_stmt FROM @r57_sql;
EXECUTE r57_stmt;
DEALLOCATE PREPARE r57_stmt;
SET @r57_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_member_commerce_settings' AND COLUMN_NAME='inherit_previous'), 'SELECT 1', 'ALTER TABLE eb_member_commerce_settings ADD COLUMN inherit_previous TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''同会员同店同业务默认上次来源''');
PREPARE r57_stmt FROM @r57_sql;
EXECUTE r57_stmt;
DEALLOCATE PREPARE r57_stmt;
SET @r57_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_member_commerce_settings' AND COLUMN_NAME='repayment_primary_id'), 'SELECT 1', 'ALTER TABLE eb_member_commerce_settings ADD COLUMN repayment_primary_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''关闭来源时补交固定一级来源''');
PREPARE r57_stmt FROM @r57_sql;
EXECUTE r57_stmt;
DEALLOCATE PREPARE r57_stmt;
SET @r57_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_member_commerce_settings' AND COLUMN_NAME='repayment_secondary_id'), 'SELECT 1', 'ALTER TABLE eb_member_commerce_settings ADD COLUMN repayment_secondary_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''关闭来源时补交固定二级来源''');
PREPARE r57_stmt FROM @r57_sql;
EXECUTE r57_stmt;
DEALLOCATE PREPARE r57_stmt;
