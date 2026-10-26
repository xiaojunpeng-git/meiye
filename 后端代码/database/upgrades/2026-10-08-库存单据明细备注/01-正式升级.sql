-- upgrade_key: 20261008-002-inventory-document-line-remark
SET NAMES utf8mb4;
SET @inventory_remark_schema := DATABASE();

-- 旧行保留为空备注；新备注随原单据事务写入，不另建可漂移的读模型。
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@inventory_remark_schema AND TABLE_NAME='eb_inventory_batch_movement_fact' AND COLUMN_NAME='detail_remark')=0, 'ALTER TABLE `eb_inventory_batch_movement_fact` ADD COLUMN `detail_remark` varchar(500) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@inventory_remark_schema AND TABLE_NAME='eb_inventory_stock_request_line' AND COLUMN_NAME='detail_remark')=0, 'ALTER TABLE `eb_inventory_stock_request_line` ADD COLUMN `detail_remark` varchar(500) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@inventory_remark_schema AND TABLE_NAME='eb_inventory_cross_transfer_line' AND COLUMN_NAME='detail_remark')=0, 'ALTER TABLE `eb_inventory_cross_transfer_line` ADD COLUMN `detail_remark` varchar(500) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@inventory_remark_schema AND TABLE_NAME='eb_inventory_salon_usage_line' AND COLUMN_NAME='detail_remark')=0, 'ALTER TABLE `eb_inventory_salon_usage_line` ADD COLUMN `detail_remark` varchar(500) NOT NULL DEFAULT ''''', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SELECT 'APPLY_OK' AS apply_result;
