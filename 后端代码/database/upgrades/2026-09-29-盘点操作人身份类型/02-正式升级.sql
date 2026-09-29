-- upgrade_key: 20260929-001-inventory-count-operator-type
-- 旧行默认门店员工；新增组织会话以 EMPLOYEE 类型和稳定 employee_id 写入。
SET @db := DATABASE();
SET @has_column := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@db AND table_name='eb_inventory_stock_count_draft' AND column_name='operator_type');
SET @sql := IF(@has_column=0,
  'ALTER TABLE `eb_inventory_stock_count_draft` ADD COLUMN `operator_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''STORE_STAFF'' AFTER `operator_id`',
  'SELECT ''skip draft.operator_type''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=@db AND table_name='eb_inventory_stock_count_draft' AND index_name='idx_typed_creator');
SET @sql := IF(@has_index=0,
  'ALTER TABLE `eb_inventory_stock_count_draft` ADD KEY `idx_typed_creator` (`tenant_id`, `store_id`, `location_id`, `operator_type`, `operator_id`, `document_status`, `updated_at`)',
  'SELECT ''skip idx_typed_creator''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_column := (SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema=@db AND table_name='eb_inventory_stock_count_document' AND column_name='operator_type');
SET @sql := IF(@has_column=0,
  'ALTER TABLE `eb_inventory_stock_count_document` ADD COLUMN `operator_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''STORE_STAFF'' AFTER `operator_id`',
  'SELECT ''skip document.operator_type''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 历史平台仓盘点不是门店员工；只按既有 store_id=0 事实修正身份类型。
UPDATE `eb_inventory_stock_count_document`
SET `operator_type`='PLATFORM_ADMIN'
WHERE `store_id`=0 AND `operator_type`='STORE_STAFF';
