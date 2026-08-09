-- upgrade_key: 20260803-006-local-cashier-whole-yuan-truncation
-- This test-only migration is intentionally irreversible without its pre-run backup.
SET NAMES utf8mb4;
SET @wy_db := DATABASE();
SET @wy_expected_db := 'ruihao_test_recovered_20260801';
SET @wy_key := '20260803-006-local-cashier-whole-yuan-truncation';

SELECT COUNT(*) INTO @wy_registered FROM eb_database_upgrade_log WHERE upgrade_key=@wy_key;
SET @wy_guard_sql := IF(@wy_db=@wy_expected_db AND @wy_registered=0,
  'SELECT ''APPLY_GUARD_OK'' AS apply_guard_result',
  'SELECT * FROM STOP_LOCAL_CASHIER_WHOLE_YUAN_APPLY_GUARD_FAILED');
PREPARE wy_guard_stmt FROM @wy_guard_sql;
EXECUTE wy_guard_stmt;
DEALLOCATE PREPARE wy_guard_stmt;

-- The procedure below executes in the database context, so this work table must
-- remain visible to it for the duration of this one migration connection.
DROP PROCEDURE IF EXISTS wy_apply_whole_yuan_cutover;
DROP TABLE IF EXISTS wy_decimal_targets;
CREATE TABLE wy_decimal_targets (
  table_name varchar(128) NOT NULL,
  column_name varchar(128) NOT NULL,
  PRIMARY KEY (table_name, column_name)
) ENGINE=Memory;

INSERT INTO wy_decimal_targets (table_name, column_name) VALUES
('eb_store_product','price'),('eb_store_product','settle_price'),('eb_store_product','vip_price'),('eb_store_product','ot_price'),('eb_store_product','postage'),('eb_store_product','cost'),
('eb_store_product_attr_value','price'),('eb_store_product_attr_value','price_range_min'),('eb_store_product_attr_value','price_range_max'),('eb_store_product_attr_value','settle_price'),('eb_store_product_attr_value','cost'),('eb_store_product_attr_value','ot_price'),('eb_store_product_attr_value','vip_price'),('eb_store_product_attr_value','brokerage'),('eb_store_product_attr_value','brokerage_two'),
('eb_store_card_related','cost'),('eb_store_card_related','price'),('eb_store_card_related','writeoff_amount'),
('eb_store_coupon_issue','coupon_price'),('eb_store_coupon_issue','top_discount_price'),('eb_store_coupon_issue','use_min_price'),
('eb_store_coupon_user','coupon_price'),('eb_store_coupon_user','use_min_price'),
('eb_store_seckill','price'),('eb_store_seckill','cost'),('eb_store_seckill','ot_price'),
('eb_store_bargain','price'),('eb_store_bargain','min_price'),('eb_store_bargain','bargain_max_price'),('eb_store_bargain','bargain_min_price'),('eb_store_bargain','cost'),
('eb_store_combination','price'),('eb_member_ship','price'),('eb_member_ship','pre_price'),
('eb_user','now_money'),('eb_user','brokerage_price'),('eb_user','ben_money'),('eb_user','give_money'),
('eb_user_money','number'),('eb_user_money','balance'),('eb_user_money','ben_money'),('eb_user_money','give_money'),('eb_user_money','ben_change_amount'),('eb_user_money','give_change_amount'),
('eb_user_recharge','price'),('eb_user_recharge','give_price'),('eb_user_recharge','debt_amount'),('eb_user_recharge','repaid_debt_amount'),('eb_user_recharge','refund_price'),('eb_user_recharge','refund_ben'),('eb_user_recharge','refund_give'),
('eb_store_debt','total_debt'),('eb_store_debt','repaid_debt'),('eb_store_debt_item','debt_amount'),('eb_store_debt_item','repaid_debt'),('eb_store_debt_repay','repay_amount'),
('eb_store_order','freight_price'),('eb_store_order','total_price'),('eb_store_order','settle_price'),('eb_store_order','total_postage'),('eb_store_order','pay_price'),('eb_store_order','cash_pay_price'),('eb_store_order','debt_amount'),('eb_store_order','repaid_debt_amount'),('eb_store_order','yue_pay_price'),('eb_store_order','paid_ben_amount'),('eb_store_order','paid_give_amount'),('eb_store_order','pay_postage'),('eb_store_order','deduction_price'),('eb_store_order','coupon_price'),('eb_store_order','promotions_price'),('eb_store_order','first_order_price'),('eb_store_order','change_price'),('eb_store_order','service_price'),('eb_store_order','refund_price'),('eb_store_order','one_brokerage'),('eb_store_order','two_brokerage'),('eb_store_order','cost'),('eb_store_order','yue_money'),
('eb_store_order_cart_info','total_price'),('eb_store_order_cart_info','settle_price'),('eb_store_order_cart_info','pay_price'),('eb_store_order_cart_info','yue_pay_amount'),('eb_store_order_cart_info','card_upgrade_amount'),('eb_store_order_cart_info','cash_pay_amount'),('eb_store_order_cart_info','debt_amount'),('eb_store_order_cart_info','repaid_debt_amount'),('eb_store_order_cart_info','pay_postage'),('eb_store_order_cart_info','member_price'),('eb_store_order_cart_info','deduction_price'),('eb_store_order_cart_info','coupon_price'),('eb_store_order_cart_info','promotions_price'),('eb_store_order_cart_info','first_order_price'),('eb_store_order_cart_info','change_price'),('eb_store_order_cart_info','service_price'),
('eb_store_reservation_order','service_price'),('eb_store_product_reservation_time','service_price'),
('eb_staff_flowing_water','pay_price'),('eb_staff_flowing_water','total_price'),('eb_staff_yeji','yeji'),('eb_staff_yeji','deduct_card_yeji'),('eb_staff_yeji','price'),('eb_staff_yeji','true_price'),
('eb_store_finance_flow','pay_price'),('eb_store_finance_flow','total_price');

DELIMITER $$
DROP PROCEDURE IF EXISTS wy_apply_whole_yuan_cutover$$
CREATE PROCEDURE wy_apply_whole_yuan_cutover()
BEGIN
  BEGIN
    DECLARE wy_done INT DEFAULT 0;
    DECLARE wy_table varchar(128);
    DECLARE wy_column varchar(128);
    DECLARE wy_cursor CURSOR FOR SELECT table_name, column_name FROM wy_decimal_targets ORDER BY table_name, column_name;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET wy_done = 1;
    OPEN wy_cursor;
    wy_decimal_loop: LOOP
      FETCH wy_cursor INTO wy_table, wy_column;
      IF wy_done = 1 THEN LEAVE wy_decimal_loop; END IF;
      SET @wy_update_sql = CONCAT('UPDATE `', wy_table, '` SET `', wy_column, '`=TRUNCATE(`', wy_column, '`,0) WHERE `', wy_column, '`<>TRUNCATE(`', wy_column, '`,0)');
      PREPARE wy_update_stmt FROM @wy_update_sql;
      EXECUTE wy_update_stmt;
      DEALLOCATE PREPARE wy_update_stmt;
    END LOOP;
    CLOSE wy_cursor;
  END;
  BEGIN
    DECLARE wy_done INT DEFAULT 0;
    DECLARE wy_table varchar(128);
    DECLARE wy_column varchar(128);
    DECLARE wy_cursor CURSOR FOR
      SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=@wy_db AND TABLE_NAME LIKE 'eb_cashier_v3_%' AND COLUMN_NAME LIKE '%cents%'
      ORDER BY TABLE_NAME, ORDINAL_POSITION;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET wy_done = 1;
    OPEN wy_cursor;
    wy_cents_loop: LOOP
      FETCH wy_cursor INTO wy_table, wy_column;
      IF wy_done = 1 THEN LEAVE wy_cents_loop; END IF;
      SET @wy_update_sql = CONCAT('UPDATE `', wy_table, '` SET `', wy_column, '`=`', wy_column, '`-MOD(`', wy_column, '`,100) WHERE MOD(`', wy_column, '`,100)<>0');
      PREPARE wy_update_stmt FROM @wy_update_sql;
      EXECUTE wy_update_stmt;
      DEALLOCATE PREPARE wy_update_stmt;
    END LOOP;
    CLOSE wy_cursor;
  END;
END$$
DELIMITER ;
CALL wy_apply_whole_yuan_cutover();
DROP PROCEDURE wy_apply_whole_yuan_cutover;
DROP TABLE wy_decimal_targets;

SELECT 'APPLY_OK' AS apply_result;
