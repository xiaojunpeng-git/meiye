-- upgrade_key: 20260803-006-local-cashier-whole-yuan-truncation
SET NAMES utf8mb4;
SET @wy_db := DATABASE();
SET @wy_expected_db := 'ruihao_test_recovered_20260801';
SET @wy_key := '20260803-006-local-cashier-whole-yuan-truncation';
SET @wy_failures := 0;

CREATE TEMPORARY TABLE wy_decimal_targets (
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

SET SESSION group_concat_max_len=1048576;
SELECT GROUP_CONCAT(CONCAT(
  'SELECT COUNT(*) AS fractional_rows FROM `', table_name, '` WHERE `', column_name, '`<>TRUNCATE(`', column_name, '`,0)'
) SEPARATOR ' UNION ALL ') INTO @wy_decimal_check_sql FROM wy_decimal_targets;
SET @wy_decimal_check_sql := CONCAT('SELECT COALESCE(SUM(fractional_rows),0) INTO @wy_decimal_fractional_rows FROM (', @wy_decimal_check_sql, ') wy_decimal_checks');
PREPARE wy_decimal_check_stmt FROM @wy_decimal_check_sql;
EXECUTE wy_decimal_check_stmt;
DEALLOCATE PREPARE wy_decimal_check_stmt;

SELECT GROUP_CONCAT(CONCAT(
  'SELECT COUNT(*) AS fractional_rows FROM `', TABLE_NAME, '` WHERE MOD(`', COLUMN_NAME, '`,100)<>0'
) SEPARATOR ' UNION ALL ') INTO @wy_cents_check_sql
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@wy_db AND TABLE_NAME LIKE 'eb_cashier_v3_%' AND COLUMN_NAME LIKE '%cents%';
SET @wy_cents_check_sql := CONCAT('SELECT COALESCE(SUM(fractional_rows),0) INTO @wy_cents_fractional_rows FROM (', @wy_cents_check_sql, ') wy_cents_checks');
PREPARE wy_cents_check_stmt FROM @wy_cents_check_sql;
EXECUTE wy_cents_check_stmt;
DEALLOCATE PREPARE wy_cents_check_stmt;

SELECT COUNT(*) INTO @wy_registered FROM eb_database_upgrade_log WHERE upgrade_key=@wy_key;
SET @wy_failures := @wy_failures
  + IF(@wy_db=@wy_expected_db,0,1)
  + IF(@wy_registered=1,0,1)
  + IF(@wy_decimal_fractional_rows=0,0,1)
  + IF(@wy_cents_fractional_rows=0,0,1);

SELECT @wy_db AS target_database, @wy_registered AS registered_upgrade_count,
       @wy_decimal_fractional_rows AS decimal_fractional_rows,
       @wy_cents_fractional_rows AS v3_fractional_cents_rows,
       @wy_failures AS postcheck_failure_count;
SET @wy_finish_sql := IF(@wy_failures=0,
  'SELECT ''POSTCHECK_OK'' AS postcheck_result',
  'SELECT * FROM STOP_LOCAL_CASHIER_WHOLE_YUAN_POSTCHECK_FAILED');
PREPARE wy_finish_stmt FROM @wy_finish_sql;
EXECUTE wy_finish_stmt;
DEALLOCATE PREPARE wy_finish_stmt;

