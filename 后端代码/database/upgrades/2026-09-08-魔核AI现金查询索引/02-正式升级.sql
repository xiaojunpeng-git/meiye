-- upgrade_key: 20260908-mohe-ai-cash-source-guard-index
-- Index-only, MySQL 5.7 compatible. Run only after target/backup/DDL window approval.
-- Preflight must reject a same-name index whose columns differ.
SET @mohe_ai_guard_columns = (SELECT CONCAT(GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ','),'|',MIN(NON_UNIQUE),'|',SUM(SUB_PART IS NOT NULL)) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_cashier_v3_order_center_void_operation' AND INDEX_NAME='idx_ai_cash_source_guard');
SET @mohe_ai_guard_ddl = IF(@mohe_ai_guard_columns IS NULL, 'ALTER TABLE `eb_cashier_v3_order_center_void_operation` ADD INDEX `idx_ai_cash_source_guard` (`tenant_id`,`source_kind`,`source_id`,`status`), ALGORITHM=INPLACE, LOCK=NONE', IF(@mohe_ai_guard_columns='tenant_id,source_kind,source_id,status|1|0', 'SET @mohe_ai_guard_index_present = 1', 'MOHE_AI_ABORT_INCOMPATIBLE_SOURCE_GUARD_INDEX'));
PREPARE mohe_ai_guard_stmt FROM @mohe_ai_guard_ddl;
EXECUTE mohe_ai_guard_stmt;
DEALLOCATE PREPARE mohe_ai_guard_stmt;
