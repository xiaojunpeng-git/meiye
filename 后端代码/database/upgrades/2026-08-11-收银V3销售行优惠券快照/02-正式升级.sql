-- upgrade_key: 20260811-001-cashier-v3-line-coupon-snapshot
-- MySQL 5.6 compatible. Adds default-only columns; no historical backfill.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `cashier_v3_add_coupon_column_if_missing`;
DELIMITER $$
CREATE PROCEDURE `cashier_v3_add_coupon_column_if_missing`(
  IN p_table varchar(64),
  IN p_column varchar(64),
  IN p_definition varchar(512)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME=p_column
  ) THEN
    SET @clc_alter_sql = CONCAT(
      'ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition
    );
    PREPARE clc_alter_stmt FROM @clc_alter_sql;
    EXECUTE clc_alter_stmt;
    DEALLOCATE PREPARE clc_alter_stmt;
  END IF;
END$$
DELIMITER ;

CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_workspace_line','coupon_user_id',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''selected member coupon authority id''');
CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_workspace_line','coupon_name_snapshot',
  'varchar(128) NOT NULL DEFAULT '''' COMMENT ''coupon name at selection''');
CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_workspace_line','coupon_discount_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''coupon-only discount''');

CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_checkout_line_draft','coupon_user_id',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''frozen member coupon authority id''');
CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_checkout_line_draft','coupon_name_snapshot',
  'varchar(128) NOT NULL DEFAULT '''' COMMENT ''frozen coupon name''');
CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_checkout_line_draft','coupon_discount_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''frozen coupon-only discount''');

CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_sales_order_line','coupon_user_id',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''consumed member coupon authority id''');
CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_sales_order_line','coupon_name_snapshot',
  'varchar(128) NOT NULL DEFAULT '''' COMMENT ''order-time coupon name''');
CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_sales_order_line','coupon_discount_cents',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''coupon-only order discount''');

CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_sale_fact','coupon_user_id',
  'bigint(20) unsigned NOT NULL DEFAULT ''0'' COMMENT ''coupon authority id on immutable sale fact''');
CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_sale_fact','coupon_name_snapshot',
  'varchar(128) NOT NULL DEFAULT '''' COMMENT ''coupon name on immutable sale fact''');
CALL cashier_v3_add_coupon_column_if_missing('eb_cashier_v3_sale_fact','coupon_discount_cents',
  'bigint(20) NOT NULL DEFAULT ''0'' COMMENT ''signed coupon-only fact discount''');

DROP PROCEDURE IF EXISTS `cashier_v3_add_coupon_column_if_missing`;
SELECT 'APPLY_OK' AS apply_result;
