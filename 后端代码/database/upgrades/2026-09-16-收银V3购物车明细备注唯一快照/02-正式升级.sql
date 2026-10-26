-- upgrade_key: 20260916-003-cashier-v3-cart-line-detail-remark-snapshot
SET NAMES utf8mb4;
SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_checkout_line_draft' AND COLUMN_NAME='detail_remark_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_checkout_line_draft` ADD COLUMN `detail_remark_snapshot` mediumtext NULL COMMENT ''购物车明细备注唯一快照'' AFTER `card_purchase_snapshot_json`',
  'SELECT ''checkout_detail_remark_column_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_sales_order_line' AND COLUMN_NAME='detail_remark_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_sales_order_line` ADD COLUMN `detail_remark_snapshot` mediumtext NULL COMMENT ''购物车明细备注唯一快照'' AFTER `card_purchase_snapshot_json`',
  'SELECT ''sales_order_detail_remark_column_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_cashier_v3_entitlement_service_fact' AND COLUMN_NAME='detail_remark_snapshot')=0,
  'ALTER TABLE `eb_cashier_v3_entitlement_service_fact` ADD COLUMN `detail_remark_snapshot` mediumtext NULL COMMENT ''购物车明细备注唯一快照'' AFTER `craftsmen_snapshot_json`',
  'SELECT ''service_fact_detail_remark_column_already_present'' AS apply_result');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO `eb_database_upgrade_log`
  (`upgrade_key`,`title`,`task_file`,`sql_checksum`,`code_version`,`executed_at`,`executed_by`,`result_note`)
SELECT
  '20260916-003-cashier-v3-cart-line-detail-remark-snapshot',
  '收银 V3 购物车明细备注唯一快照',
  'database/upgrades/2026-09-16-收银V3购物车明细备注唯一快照/02-正式升级.sql',
  '',
  'WORKTREE',
  NOW(),
  'Codex',
  '新增结账草稿、销售订单及服务事实的明细备注快照字段'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM `eb_database_upgrade_log`
  WHERE `upgrade_key`='20260916-003-cashier-v3-cart-line-detail-remark-snapshot'
);

SELECT 'APPLY_OK' AS apply_result;
