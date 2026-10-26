-- upgrade_key: 20260730-022-cashier-v3-sale-hang-resume-checkout-v1
-- MySQL 5.6.51 compatible and replay-safe for every column/index statement.
SET NAMES utf8mb4;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_contract_version');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `resume_contract_version` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `contract_version`',
 'SELECT ''resume_contract_version already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_workspace_id');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `resume_workspace_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `state_context_id`',
 'SELECT ''resume_workspace_id already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_state_context_id');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `resume_state_context_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `resume_workspace_id`',
 'SELECT ''resume_state_context_id already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resume_command_idempotency_key');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `resume_command_idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `resume_state_context_id`',
 'SELECT ''resume_command_idempotency_key already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='resumed_at');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `resumed_at` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `resume_command_idempotency_key`',
 'SELECT ''resumed_at already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='checkout_request_id');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `checkout_request_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `resumed_at`',
 'SELECT ''checkout_request_id already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='sales_order_id');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `sales_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `checkout_request_id`',
 'SELECT ''sales_order_id already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND COLUMN_NAME='settled_at');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD COLUMN `settled_at` bigint(20) unsigned NOT NULL DEFAULT ''0'' AFTER `sales_order_id`',
 'SELECT ''settled_at already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order_line' AND COLUMN_NAME='workspace_snapshot_json');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order_line` ADD COLUMN `workspace_snapshot_json` longtext NULL AFTER `line_snapshot_json`',
 'SELECT ''workspace_snapshot_json already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_workspace_draft' AND COLUMN_NAME='resumed_hang_order_id');
SET @hr_sql := IF(@hr_column_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_draft` ADD COLUMN `resumed_hang_order_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '''' AFTER `line_fingerprint`',
 'SELECT ''resumed_hang_order_id already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND INDEX_NAME='idx_resume_workspace_status');
SET @hr_sql := IF(@hr_index_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD KEY `idx_resume_workspace_status` (`tenant_id`,`store_id`,`resume_workspace_id`,`hang_status`,`id`)',
 'SELECT ''idx_resume_workspace_status already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_hang_order' AND INDEX_NAME='idx_checkout_request');
SET @hr_sql := IF(@hr_index_exists=0,
 'ALTER TABLE `eb_cashier_v3_hang_order` ADD KEY `idx_checkout_request` (`tenant_id`,`store_id`,`checkout_request_id`,`id`)',
 'SELECT ''idx_checkout_request already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SET @hr_index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_workspace_draft' AND INDEX_NAME='idx_resumed_hang_order');
SET @hr_sql := IF(@hr_index_exists=0,
 'ALTER TABLE `eb_cashier_v3_workspace_draft` ADD KEY `idx_resumed_hang_order` (`resumed_hang_order_id`,`id`)',
 'SELECT ''idx_resumed_hang_order already exists'' AS apply_note');
PREPARE hr_stmt FROM @hr_sql; EXECUTE hr_stmt; DEALLOCATE PREPARE hr_stmt;

SELECT 'APPLY_OK' AS apply_result;
