-- =============================================================================
-- detailYeji 提成核对 SQL（sum_type=2 耗卡/劳动业绩）
-- 接口参数: staff_id=75, data=2026/06/01-2026/06/30, page=1, limit=20
--
-- 提成公式（与 PHP 一致）:
--   commission = ROUND(yeji * commission_pct / 100, 2)
--   commission_pct 来自 eb_yeji_commission.commission_json，按「当月现金业绩」落档
--
-- 当月现金业绩 = 该员工当月 type IN (1,2) 且关联有效现金订单的 SUM(yeji)
-- 表前缀默认 eb_，请按实际库修改
-- =============================================================================

SET @staff_id   = 75;
SET @date_start = '2026/06/01';
SET @date_end   = '2026/06/30 23:59:59';
-- 本例查询区间在单月内，当月现金业绩月份固定为 2026-06
SET @month_start = '2026/06/01';
SET @month_end   = '2026/06/30 23:59:59';


-- =============================================================================
-- 步骤1：有效现金订单 ID（findMonthYeji / sum_type=1 用的子查询）
-- 条件来源: StaffYejiDao::search + ValidCashOrderServices::applyScope
-- =============================================================================
-- 可先单独跑，确认有效订单数量
SELECT COUNT(*) AS valid_cash_order_cnt
FROM eb_store_order o
WHERE o.order_type IN (0, 1)
  AND (o.pid >= 0 OR o.pid = -2)
  AND o.paid = 1
  AND o.refund_status = 0
  AND o.is_del = 0
  AND o.is_system_del = 0
  AND o.add_time BETWEEN UNIX_TIMESTAMP(@month_start) AND UNIX_TIMESTAMP(@month_end)
  AND (
        o.cash_pay_price > 0
        OR (
            o.pay_type = 'combination'
            AND EXISTS (
                SELECT 1
                FROM eb_combination_order co
                WHERE co.order_id = o.id
                  AND co.cash_choose <> 9
                  AND co.active_pay <> 3
            )
        )
      )
  AND (
        (o.pay_type <> 'combination' AND IFNULL(o.cash_choose, 0) <> 9)
        OR (
            o.pay_type = 'combination'
            AND EXISTS (
                SELECT 1
                FROM eb_combination_order co2
                WHERE co2.order_id = o.id
                  AND co2.cash_choose <> 9
                  AND co2.active_pay <> 3
            )
        )
      );


-- =============================================================================
-- 步骤2：员工 75 在 2026-06 的「当月现金业绩」(monthYeji)
-- 对应 PHP: StaffYejiDao::findMonthYeji($staffId, $date)
-- =============================================================================
SELECT ROUND(IFNULL(SUM(sy.yeji), 0), 2) AS month_cash_yeji
FROM eb_staff_yeji sy
WHERE sy.status = 0
  AND sy.staff_id = @staff_id
  AND sy.type IN (1, 2)
  AND sy.created_time BETWEEN @month_start AND @month_end
  AND sy.order_id IN (
        SELECT o.id
        FROM eb_store_order o
        WHERE o.order_type IN (0, 1)
          AND (o.pid >= 0 OR o.pid = -2)
          AND o.paid = 1
          AND o.refund_status = 0
          AND o.is_del = 0
          AND o.is_system_del = 0
          AND o.add_time BETWEEN UNIX_TIMESTAMP(@month_start) AND UNIX_TIMESTAMP(@month_end)
          AND (
                o.cash_pay_price > 0
                OR (
                    o.pay_type = 'combination'
                    AND EXISTS (
                        SELECT 1 FROM eb_combination_order co
                        WHERE co.order_id = o.id AND co.cash_choose <> 9 AND co.active_pay <> 3
                    )
                )
              )
          AND (
                (o.pay_type <> 'combination' AND IFNULL(o.cash_choose, 0) <> 9)
                OR (
                    o.pay_type = 'combination'
                    AND EXISTS (
                        SELECT 1 FROM eb_combination_order co2
                        WHERE co2.order_id = o.id AND co2.cash_choose <> 9 AND co2.active_pay <> 3
                    )
                )
              )
    );


-- =============================================================================
-- 步骤3：耗卡明细 + 每条提成（与 detailYeji 列表 commission 字段一致）
-- page=1, limit=20 => ORDER BY id DESC LIMIT 20 OFFSET 0
-- =============================================================================
WITH valid_cash_orders AS (
    SELECT o.id
    FROM eb_store_order o
    WHERE o.order_type IN (0, 1)
      AND (o.pid >= 0 OR o.pid = -2)
      AND o.paid = 1
      AND o.refund_status = 0
      AND o.is_del = 0
      AND o.is_system_del = 0
      AND o.add_time BETWEEN UNIX_TIMESTAMP(@month_start) AND UNIX_TIMESTAMP(@month_end)
      AND (
            o.cash_pay_price > 0
            OR (
                o.pay_type = 'combination'
                AND EXISTS (
                    SELECT 1 FROM eb_combination_order co
                    WHERE co.order_id = o.id AND co.cash_choose <> 9 AND co.active_pay <> 3
                )
            )
          )
      AND (
            (o.pay_type <> 'combination' AND IFNULL(o.cash_choose, 0) <> 9)
            OR (
                o.pay_type = 'combination'
                AND EXISTS (
                    SELECT 1 FROM eb_combination_order co2
                    WHERE co2.order_id = o.id AND co2.cash_choose <> 9 AND co2.active_pay <> 3
                )
            )
          )
),
month_cash AS (
    SELECT ROUND(IFNULL(SUM(sy.yeji), 0), 2) AS month_cash_yeji
    FROM eb_staff_yeji sy
    INNER JOIN valid_cash_orders vco ON vco.id = sy.order_id
    WHERE sy.status = 0
      AND sy.staff_id = @staff_id
      AND sy.type IN (1, 2)
      AND sy.created_time BETWEEN @month_start AND @month_end
),
labor_rows AS (
    SELECT
        sy.id,
        sy.staff_id,
        sy.staff_name,
        sy.goods_id,
        sy.yeji,
        sy.link_id,
        sy.created_time,
        COALESCE(NULLIF(p.pid, 0), sy.goods_id) AS parent_product_id
    FROM eb_staff_yeji sy
    LEFT JOIN eb_store_product p ON p.id = sy.goods_id
    WHERE sy.status = 0
      AND sy.staff_id = @staff_id
      AND sy.type = 3
      AND sy.created_time BETWEEN @date_start AND @date_end
    ORDER BY sy.id DESC
    LIMIT 20 OFFSET 0
)
SELECT
    lr.id,
    lr.staff_name,
    lr.goods_id,
    lr.parent_product_id,
    sp.store_name AS product_name,
    lr.yeji,
    lr.created_time,
    mc.month_cash_yeji,
    CONCAT(yr.yeji_min, '-', yr.yeji_max) AS commission_range,
    CAST(
        JSON_UNQUOTE(
            JSON_EXTRACT(
                yc.commission_json,
                CONCAT('$.\"', yr.yeji_min, '-', yr.yeji_max, '\"')
            )
        ) AS DECIMAL(10, 2)
    ) AS commission_pct,
    ROUND(
        lr.yeji * CAST(
            JSON_UNQUOTE(
                JSON_EXTRACT(
                    yc.commission_json,
                    CONCAT('$.\"', yr.yeji_min, '-', yr.yeji_max, '\"')
                )
            ) AS DECIMAL(10, 2)
        ) / 100,
        2
    ) AS commission
FROM labor_rows lr
CROSS JOIN month_cash mc
LEFT JOIN eb_store_product sp ON sp.id = lr.goods_id
LEFT JOIN eb_yeji_commission yc ON yc.product_id = lr.parent_product_id
LEFT JOIN eb_yeji_range yr
    ON mc.month_cash_yeji >= yr.yeji_min
   AND mc.month_cash_yeji <= yr.yeji_max
   AND JSON_EXTRACT(
           yc.commission_json,
           CONCAT('$.\"', yr.yeji_min, '-', yr.yeji_max, '\"')
       ) IS NOT NULL
ORDER BY lr.id DESC;


-- =============================================================================
-- 步骤4：当前页 20 条提成合计（对应列表页 commission 相加）
-- =============================================================================
WITH valid_cash_orders AS (
    SELECT o.id
    FROM eb_store_order o
    WHERE o.order_type IN (0, 1)
      AND (o.pid >= 0 OR o.pid = -2)
      AND o.paid = 1
      AND o.refund_status = 0
      AND o.is_del = 0
      AND o.is_system_del = 0
      AND o.add_time BETWEEN UNIX_TIMESTAMP(@month_start) AND UNIX_TIMESTAMP(@month_end)
      AND (
            o.cash_pay_price > 0
            OR (
                o.pay_type = 'combination'
                AND EXISTS (
                    SELECT 1 FROM eb_combination_order co
                    WHERE co.order_id = o.id AND co.cash_choose <> 9 AND co.active_pay <> 3
                )
            )
          )
      AND (
            (o.pay_type <> 'combination' AND IFNULL(o.cash_choose, 0) <> 9)
            OR (
                o.pay_type = 'combination'
                AND EXISTS (
                    SELECT 1 FROM eb_combination_order co2
                    WHERE co2.order_id = o.id AND co2.cash_choose <> 9 AND co2.active_pay <> 3
                )
            )
          )
),
month_cash AS (
    SELECT ROUND(IFNULL(SUM(sy.yeji), 0), 2) AS month_cash_yeji
    FROM eb_staff_yeji sy
    INNER JOIN valid_cash_orders vco ON vco.id = sy.order_id
    WHERE sy.status = 0
      AND sy.staff_id = @staff_id
      AND sy.type IN (1, 2)
      AND sy.created_time BETWEEN @month_start AND @month_end
),
labor_rows AS (
    SELECT
        sy.id,
        sy.yeji,
        COALESCE(NULLIF(p.pid, 0), sy.goods_id) AS parent_product_id
    FROM eb_staff_yeji sy
    LEFT JOIN eb_store_product p ON p.id = sy.goods_id
    WHERE sy.status = 0
      AND sy.staff_id = @staff_id
      AND sy.type = 3
      AND sy.created_time BETWEEN @date_start AND @date_end
    ORDER BY sy.id DESC
    LIMIT 20 OFFSET 0
),
row_commission AS (
    SELECT
        lr.id,
        ROUND(
            lr.yeji * IFNULL(
                CAST(
                    JSON_UNQUOTE(
                        JSON_EXTRACT(
                            yc.commission_json,
                            CONCAT('$.\"', yr.yeji_min, '-', yr.yeji_max, '\"')
                        )
                    ) AS DECIMAL(10, 2)
                ),
                0
            ) / 100,
            2
        ) AS commission
    FROM labor_rows lr
    CROSS JOIN month_cash mc
    LEFT JOIN eb_yeji_commission yc ON yc.product_id = lr.parent_product_id
    LEFT JOIN eb_yeji_range yr
        ON mc.month_cash_yeji >= yr.yeji_min
       AND mc.month_cash_yeji <= yr.yeji_max
       AND JSON_EXTRACT(
               yc.commission_json,
               CONCAT('$.\"', yr.yeji_min, '-', yr.yeji_max, '\"')
           ) IS NOT NULL
)
SELECT ROUND(SUM(commission), 2) AS page_commission_total
FROM row_commission;


-- =============================================================================
-- 步骤5：整个查询区间全部耗卡提成合计（对应 totalCommission / yejiInfo.service_commission）
-- 去掉 LIMIT 即全量
-- =============================================================================
WITH valid_cash_orders AS (
    SELECT o.id
    FROM eb_store_order o
    WHERE o.order_type IN (0, 1)
      AND (o.pid >= 0 OR o.pid = -2)
      AND o.paid = 1
      AND o.refund_status = 0
      AND o.is_del = 0
      AND o.is_system_del = 0
      AND o.add_time BETWEEN UNIX_TIMESTAMP(@month_start) AND UNIX_TIMESTAMP(@month_end)
      AND (
            o.cash_pay_price > 0
            OR (
                o.pay_type = 'combination'
                AND EXISTS (
                    SELECT 1 FROM eb_combination_order co
                    WHERE co.order_id = o.id AND co.cash_choose <> 9 AND co.active_pay <> 3
                )
            )
          )
      AND (
            (o.pay_type <> 'combination' AND IFNULL(o.cash_choose, 0) <> 9)
            OR (
                o.pay_type = 'combination'
                AND EXISTS (
                    SELECT 1 FROM eb_combination_order co2
                    WHERE co2.order_id = o.id AND co2.cash_choose <> 9 AND co2.active_pay <> 3
                )
            )
          )
),
month_cash AS (
    SELECT ROUND(IFNULL(SUM(sy.yeji), 0), 2) AS month_cash_yeji
    FROM eb_staff_yeji sy
    INNER JOIN valid_cash_orders vco ON vco.id = sy.order_id
    WHERE sy.status = 0
      AND sy.staff_id = @staff_id
      AND sy.type IN (1, 2)
      AND sy.created_time BETWEEN @month_start AND @month_end
),
labor_rows AS (
    SELECT
        sy.id,
        sy.yeji,
        sy.created_time,
        COALESCE(NULLIF(p.pid, 0), sy.goods_id) AS parent_product_id
    FROM eb_staff_yeji sy
    LEFT JOIN eb_store_product p ON p.id = sy.goods_id
    WHERE sy.status = 0
      AND sy.staff_id = @staff_id
      AND sy.type = 3
      AND sy.created_time BETWEEN @date_start AND @date_end
)
SELECT ROUND(SUM(
    ROUND(
        sy.yeji * IFNULL(
        CAST(
            JSON_UNQUOTE(
                JSON_EXTRACT(
                    yc.commission_json,
                    CONCAT('$.\"', yr.yeji_min, '-', yr.yeji_max, '\"')
                )
            ) AS DECIMAL(10, 2)
        ),
        0
        ) / 100,
        2
    )
), 2) AS total_commission
FROM labor_rows sy
CROSS JOIN month_cash mc
LEFT JOIN eb_yeji_commission yc ON yc.product_id = sy.parent_product_id
LEFT JOIN eb_yeji_range yr
    ON mc.month_cash_yeji >= yr.yeji_min
   AND mc.month_cash_yeji <= yr.yeji_max
   AND JSON_EXTRACT(
           yc.commission_json,
           CONCAT('$.\"', yr.yeji_min, '-', yr.yeji_max, '\"')
       ) IS NOT NULL;
