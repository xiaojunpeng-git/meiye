-- upgrade_key: 20261010-001-cashier-v3-coupon-tender
-- MySQL 5.6 compatible. Existing coupon_discount_cents remain historical discounts.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `cashier_v3_add_coupon_tender_column`;
DELIMITER $$
CREATE PROCEDURE `cashier_v3_add_coupon_tender_column`(IN p_table varchar(64))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND COLUMN_NAME='coupon_payment_cents'
  ) THEN
    SET @coupon_tender_sql = CONCAT('ALTER TABLE `', p_table,
      '` ADD COLUMN `coupon_payment_cents` bigint(20) NOT NULL DEFAULT 0 COMMENT ''non-cash coupon tender, signed on reversal facts''');
    PREPARE coupon_tender_stmt FROM @coupon_tender_sql;
    EXECUTE coupon_tender_stmt;
    DEALLOCATE PREPARE coupon_tender_stmt;
  END IF;
END$$
DELIMITER ;

CALL cashier_v3_add_coupon_tender_column('eb_cashier_v3_checkout_line_draft');
CALL cashier_v3_add_coupon_tender_column('eb_cashier_v3_sales_order_line');
CALL cashier_v3_add_coupon_tender_column('eb_cashier_v3_sale_fact');

DROP PROCEDURE IF EXISTS `cashier_v3_add_coupon_tender_column`;
SELECT 'APPLY_OK' AS apply_result;
