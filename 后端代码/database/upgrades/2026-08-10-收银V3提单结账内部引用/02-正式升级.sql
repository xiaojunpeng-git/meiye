-- upgrade_key: 20260810-002-cashier-v3-resumed-hang-checkout-reference
-- 已提取挂单仅作为成功结账后删除草稿的内部引用，不属于结账来源或资源锁。
SET NAMES utf8mb4;

SET @rh_db := DATABASE();
SET @rh_table := 'eb_cashier_v3_checkout_request';
SET @rh_column := 'resumed_hang_order_id';
SET @rh_sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@rh_db AND TABLE_NAME=@rh_table AND COLUMN_NAME=@rh_column)=0,
  'ALTER TABLE `eb_cashier_v3_checkout_request` ADD COLUMN `resumed_hang_order_id` varchar(64) NOT NULL DEFAULT '''' AFTER `source_document_no`',
  'SELECT ''checkout resumed hang reference already exists'' AS apply_note');
PREPARE rh_stmt FROM @rh_sql; EXECUTE rh_stmt; DEALLOCATE PREPARE rh_stmt;

SELECT 'APPLY_OK' AS apply_result;
