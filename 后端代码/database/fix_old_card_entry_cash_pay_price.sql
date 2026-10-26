-- =============================================================================
-- 历史数据修复：旧数据录入（cash_choose = 9）
-- 目标：
--   1. eb_store_order.cash_pay_price = 0
--   2. eb_store_order_cart_info.cash_pay_amount = 0（若存在该字段）
--   3. eb_staff_yeji 销售业绩 type=1/2 的 yeji、price 置 0
--
-- 表前缀默认 eb_，如不同请全局替换。
-- 建议：先执行「一、预览」确认影响行数，再执行「二、修复」。
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 一、预览（执行修复前先跑一遍）
-- -----------------------------------------------------------------------------

-- 1.1 商品/收银订单（含充值影子单 order_type=1，不含核销子单 order_type=2）
SELECT id, order_id, pay_type, order_type, cash_choose, pay_price, cash_pay_price, yue_pay_price, add_time
FROM eb_store_order
WHERE cash_choose = 9
  AND order_type <> 2
  AND IFNULL(cash_pay_price, 0) <> 0
ORDER BY id DESC
LIMIT 200;

-- 1.2 储值单
SELECT id, order_id, recharge_type, cash_choose, price, add_time
FROM eb_user_recharge
WHERE cash_choose = 9
ORDER BY id DESC
LIMIT 200;

-- 1.3 待清零的商品行现金（需表存在 cash_pay_amount 字段）
SELECT c.id, c.oid, c.product_id, c.pay_price, c.cash_pay_amount
FROM eb_store_order_cart_info c
INNER JOIN eb_store_order o ON o.id = c.oid
WHERE o.cash_choose = 9
  AND o.order_type <> 2
  AND IFNULL(c.cash_pay_amount, 0) <> 0
LIMIT 200;

-- 1.4 待清零的销售业绩（购卡 type=2）
SELECT sy.id, sy.link_id, sy.type, sy.staff_name, sy.yeji, sy.price, o.cash_choose, o.order_id
FROM eb_staff_yeji sy
INNER JOIN eb_store_order o ON o.id = sy.link_id
WHERE sy.type = 2
  AND o.cash_choose = 9
  AND o.order_type <> 2
  AND (IFNULL(sy.yeji, 0) <> 0 OR IFNULL(sy.price, 0) <> 0)
LIMIT 200;

-- 1.5 待清零的销售业绩（充值 type=1）
SELECT sy.id, sy.link_id, sy.type, sy.staff_name, sy.yeji, sy.price, r.cash_choose, r.order_id
FROM eb_staff_yeji sy
INNER JOIN eb_user_recharge r ON r.id = sy.link_id
WHERE sy.type = 1
  AND r.cash_choose = 9
  AND (IFNULL(sy.yeji, 0) <> 0 OR IFNULL(sy.price, 0) <> 0)
LIMIT 200;


-- -----------------------------------------------------------------------------
-- 二、修复（确认预览无误后执行）
-- -----------------------------------------------------------------------------

START TRANSACTION;

-- 2.1 订单现金支付金额
UPDATE eb_store_order
SET cash_pay_price = 0
WHERE cash_choose = 9
  AND order_type <> 2
  AND IFNULL(cash_pay_price, 0) <> 0;

-- 2.2 充值影子单：link 到 cash_choose=9 的储值单（防止主表未同步 cash_choose）
UPDATE eb_store_order o
INNER JOIN eb_user_recharge r ON r.id = o.link_id AND o.order_type = 1
SET o.cash_pay_price = 0,
    o.cash_choose = 9
WHERE r.cash_choose = 9
  AND IFNULL(o.cash_pay_price, 0) <> 0;

-- 2.3 商品行现金金额（无 cash_pay_amount 字段则跳过本句）
UPDATE eb_store_order_cart_info c
INNER JOIN eb_store_order o ON o.id = c.oid
SET c.cash_pay_amount = 0
WHERE o.cash_choose = 9
  AND o.order_type <> 2
  AND IFNULL(c.cash_pay_amount, 0) <> 0;

-- 2.4 购卡销售业绩 type=2
UPDATE eb_staff_yeji sy
INNER JOIN eb_store_order o ON o.id = sy.link_id
SET sy.yeji = 0,
    sy.price = 0,
    sy.once_price = 0,
    sy.true_price = 0
WHERE sy.type = 2
  AND o.cash_choose = 9
  AND o.order_type <> 2
  AND (IFNULL(sy.yeji, 0) <> 0 OR IFNULL(sy.price, 0) <> 0);

-- 2.5 充值销售业绩 type=1
UPDATE eb_staff_yeji sy
INNER JOIN eb_user_recharge r ON r.id = sy.link_id
SET sy.yeji = 0,
    sy.price = 0,
    sy.once_price = 0,
    sy.true_price = 0
WHERE sy.type = 1
  AND r.cash_choose = 9
  AND (IFNULL(sy.yeji, 0) <> 0 OR IFNULL(sy.price, 0) <> 0);

-- 2.6 扣卡业绩字段（若表有 deduct_card_yeji 字段，旧数据录入也不计现金类业绩）
-- UPDATE eb_staff_yeji sy
-- INNER JOIN eb_store_order o ON o.id = sy.link_id
-- SET sy.deduct_card_yeji = 0
-- WHERE sy.type = 2 AND o.cash_choose = 9 AND o.order_type <> 2 AND IFNULL(sy.deduct_card_yeji, 0) <> 0;

COMMIT;
-- 如有问题：ROLLBACK;


-- -----------------------------------------------------------------------------
-- 三、修复后抽查
-- -----------------------------------------------------------------------------

SELECT COUNT(*) AS remain_bad_orders
FROM eb_store_order
WHERE cash_choose = 9 AND order_type <> 2 AND IFNULL(cash_pay_price, 0) <> 0;

SELECT COUNT(*) AS remain_bad_yeji_purchase
FROM eb_staff_yeji sy
INNER JOIN eb_store_order o ON o.id = sy.link_id
WHERE sy.type = 2 AND o.cash_choose = 9 AND o.order_type <> 2
  AND (IFNULL(sy.yeji, 0) <> 0 OR IFNULL(sy.price, 0) <> 0);

SELECT COUNT(*) AS remain_bad_yeji_recharge
FROM eb_staff_yeji sy
INNER JOIN eb_user_recharge r ON r.id = sy.link_id
WHERE sy.type = 1 AND r.cash_choose = 9
  AND (IFNULL(sy.yeji, 0) <> 0 OR IFNULL(sy.price, 0) <> 0);
