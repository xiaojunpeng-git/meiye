-- upgrade_key: 20261014-001-card-project-labor-fee
-- 项目主规则仅提供首次赋值；此后卡内关联值是当前配置来源。
SET NAMES utf8mb4;
SET @card_labor_column := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='labor_fee_cents');
SET @card_labor_ddl := IF(@card_labor_column=0,
  'ALTER TABLE `eb_store_card_related` ADD COLUMN `labor_fee_cents` bigint(20) unsigned NULL AFTER `writeoff_amount`',
  'SELECT ''card_labor_column_already_present'' AS apply_result');
PREPARE card_labor_stmt FROM @card_labor_ddl;
EXECUTE card_labor_stmt;
DEALLOCATE PREPARE card_labor_stmt;

-- 只填未赋值的旧行；重跑不会覆盖运营在卡内手动改过的金额。
UPDATE eb_store_card_related r
JOIN eb_store_product p ON p.id=r.product_id AND p.product_type=6
LEFT JOIN eb_cashier_v3_project_performance_rule rule_row
  ON rule_row.tenant_id='0' AND rule_row.project_id=IF(p.type=1 AND p.pid>0,p.pid,p.id)
SET r.labor_fee_cents=COALESCE(rule_row.labor_configured_unit_amount_cents,0)
WHERE r.product_type=6 AND r.labor_fee_cents IS NULL;
UPDATE eb_store_card_related SET labor_fee_cents=0 WHERE labor_fee_cents IS NULL;
SET @card_labor_nullable := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eb_store_card_related' AND COLUMN_NAME='labor_fee_cents');
SET @card_labor_ddl := IF(@card_labor_nullable='YES',
  'ALTER TABLE `eb_store_card_related` MODIFY COLUMN `labor_fee_cents` bigint(20) unsigned NOT NULL DEFAULT 0',
  'SELECT ''card_labor_backfill_already_complete'' AS apply_result');
PREPARE card_labor_stmt FROM @card_labor_ddl;
EXECUTE card_labor_stmt;
DEALLOCATE PREPARE card_labor_stmt;

