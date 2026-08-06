-- upgrade_key: 20260805-001-cashier-v3-more-actions-authority
-- MySQL 5.6 compatible. Adds empty/default columns only; no UPDATE and no old-data backfill.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `cashier_v3_add_column_if_missing`;
DELIMITER $$
CREATE PROCEDURE `cashier_v3_add_column_if_missing`(
  IN p_table varchar(64),
  IN p_column varchar(64),
  IN p_definition varchar(512)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
  ) THEN
    SET @cma_alter_sql = CONCAT(
      'ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition
    );
    PREPARE cma_alter_stmt FROM @cma_alter_sql;
    EXECUTE cma_alter_stmt;
    DEALLOCATE PREPARE cma_alter_stmt;
  END IF;
END$$
DELIMITER ;

CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_draft','order_note',
  'varchar(500) NOT NULL DEFAULT '''' COMMENT ''current checkout order note''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_draft','supplement_enabled',
  'tinyint(3) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_draft','supplement_business_date',
  'date NULL DEFAULT NULL COMMENT ''reporting date only; never operation time''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_draft','supplement_reason',
  'varchar(255) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_draft','supplement_operator_id',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_draft','supplement_operator_name_snapshot',
  'varchar(128) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_draft','supplement_operated_at',
  'int(11) unsigned NOT NULL DEFAULT ''0'' COMMENT ''real supplement operation time''');

CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_line','configured_cost_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_line','debt_amount_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''current V3 cart line debt intent''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_line','price_change_reason',
  'varchar(255) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_line','price_changed_by',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_line','price_changed_by_name_snapshot',
  'varchar(128) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_workspace_line','price_changed_at',
  'int(11) unsigned NOT NULL DEFAULT ''0''');

CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_request','order_note',
  'varchar(500) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_request','supplement_enabled',
  'tinyint(3) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_request','supplement_reason',
  'varchar(255) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_request','supplement_operator_id',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_request','supplement_operator_name_snapshot',
  'varchar(128) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_request','supplement_operated_at',
  'int(11) unsigned NOT NULL DEFAULT ''0''');

CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_line_draft','configured_cost_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_line_draft','debt_amount_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''frozen debt for this checkout line''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_line_draft','price_change_reason',
  'varchar(255) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_line_draft','price_changed_by',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_line_draft','price_changed_by_name_snapshot',
  'varchar(128) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_checkout_line_draft','price_changed_at',
  'int(11) unsigned NOT NULL DEFAULT ''0''');

CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order','order_note',
  'varchar(500) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order','supplement_enabled',
  'tinyint(3) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order','supplement_reason',
  'varchar(255) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order','supplement_operator_id',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order','supplement_operator_name_snapshot',
  'varchar(128) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order','supplement_operated_at',
  'int(11) unsigned NOT NULL DEFAULT ''0''');

CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order_line','configured_cost_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order_line','debt_amount_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''debt belonging to this V3 sales line''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order_line','price_change_reason',
  'varchar(255) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order_line','price_changed_by',
  'bigint(20) unsigned NOT NULL DEFAULT ''0''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order_line','price_changed_by_name_snapshot',
  'varchar(128) NOT NULL DEFAULT ''''');
CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sales_order_line','price_changed_at',
  'int(11) unsigned NOT NULL DEFAULT ''0''');

CALL cashier_v3_add_column_if_missing('eb_cashier_v3_sale_fact','debt_amount_cents',
  'bigint(20) NOT NULL DEFAULT ''0'' COMMENT ''debt belonging to this immutable sale fact''');

DROP PROCEDURE IF EXISTS `cashier_v3_add_column_if_missing`;
SELECT 'APPLY_OK' AS apply_result;
