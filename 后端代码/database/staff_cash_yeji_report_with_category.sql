-- 员工现金业绩报表（含项目分类）
-- 分类来源：eb_store_product_category；充值订单固定显示「充值」

SELECT
    `门店`,
    `员工`,
    `项目`,
    `分类`,
    ROUND(SUM(`现金业绩`), 2) AS `现金业绩`,
    GROUP_CONCAT(DISTINCT `订单号` ORDER BY `订单号` SEPARATOR ', ') AS `订单号`
FROM (
    -- 销售业绩 (type=2)
    SELECT
        b.name AS `门店`,
        h.staff_name AS `员工`,
        IFNULL(parent.store_name, cp.store_name) AS `项目`,
        IFNULL(
            (
                SELECT GROUP_CONCAT(DISTINCT pct.cate_name ORDER BY pct.sort DESC, pct.id SEPARATOR ',')
                FROM eb_store_product_category pct
                WHERE IFNULL(IFNULL(NULLIF(parent.cate_id, ''), cp.cate_id), '') <> ''
                  AND FIND_IN_SET(
                      pct.id,
                      REPLACE(IFNULL(NULLIF(parent.cate_id, ''), cp.cate_id), ' ', '')
                  )
            ),
            ''
        ) AS `分类`,
        ci.cart_num / osc.order_staff_cnt AS `现金业绩`,
        q.order_id AS `订单号`
    FROM eb_store_order_cart_info ci
    INNER JOIN eb_store_order q ON q.id = ci.oid
        AND q.refund_status IN (0, 3)
        AND q.paid = 1
        AND q.is_del = 0
        AND q.is_system_del = 0
        AND (q.pid IN (0, -2) OR q.pid > 0)
        AND q.order_id LIKE CONCAT('%', '{{order_id}}', '%')
    INNER JOIN eb_store_product cp ON cp.id = ci.product_id
    LEFT JOIN eb_store_product parent ON parent.id = cp.pid AND cp.pid > 0
    INNER JOIN eb_staff_yeji a ON COALESCE(NULLIF(a.order_id, 0), a.link_id) = ci.oid
        AND a.type = 2
        AND a.status = 0
        AND (
            (IFNULL(a.cart_id, 0) > 0 AND ci.cart_id = a.cart_id)
            OR (IFNULL(a.cart_id, 0) = 0 AND (a.goods_id = ci.product_id OR a.goods_id = cp.pid OR cp.id = a.goods_id))
        )
    INNER JOIN eb_system_store_staff h ON h.id = a.staff_id AND h.is_del = 0
    LEFT JOIN eb_system_store b ON h.store_id = b.id
    INNER JOIN (
        SELECT
            t.order_id AS cnt_order_id,
            COUNT(DISTINCT t.staff_id) AS order_staff_cnt
        FROM (
            SELECT DISTINCT
                COALESCE(NULLIF(sy.order_id, 0), sy.link_id) AS order_id,
                sy.staff_id
            FROM eb_staff_yeji sy
            INNER JOIN eb_store_order o ON o.id = COALESCE(NULLIF(sy.order_id, 0), sy.link_id)
                AND o.refund_status IN (0, 3)
                AND o.paid = 1
                AND o.is_del = 0
                AND o.is_system_del = 0
                AND o.order_id LIKE CONCAT('%', '{{order_id}}', '%')
            INNER JOIN eb_system_store_staff st ON st.id = sy.staff_id AND st.is_del = 0
            WHERE sy.type = 2 AND sy.status = 0
              AND st.store_id LIKE CONCAT('%', '{{store_id}}', '%')
              AND st.staff_name LIKE CONCAT('%', '{{yuangong}}', '%')
              AND sy.created_time >= '{{date_start}}'
              AND sy.created_time <= '{{date_end}}'
        ) t
        GROUP BY t.order_id
    ) osc ON osc.cnt_order_id = ci.oid
    WHERE h.store_id LIKE CONCAT('%', '{{store_id}}', '%')
      AND h.staff_name LIKE CONCAT('%', '{{yuangong}}', '%')
      AND a.created_time >= '{{date_start}}'
      AND a.created_time <= '{{date_end}}'
      AND IFNULL(parent.store_name, cp.store_name) LIKE CONCAT('%', '{{project}}', '%')
      AND IFNULL(parent.store_name, cp.store_name) <> ''
      AND osc.order_staff_cnt > 0

    UNION ALL

    -- 充值业绩 (type=1)
    SELECT
        s.name AS `门店`,
        h.staff_name AS `员工`,
        '充值' AS `项目`,
        '充值' AS `分类`,
        r.pay_price AS `现金业绩`,
        r.order_id AS `订单号`
    FROM eb_staff_yeji a
    INNER JOIN eb_store_recharge r ON COALESCE(NULLIF(a.order_id, 0), a.link_id) = r.id
        AND r.order_id LIKE CONCAT('%', '{{order_id}}', '%')
    INNER JOIN eb_system_store_staff h ON h.id = a.staff_id AND h.is_del = 0
    INNER JOIN eb_system_store s ON h.store_id = s.id
    WHERE a.type = 1 AND a.status = 0
      AND r.status = 1
      AND h.store_id LIKE CONCAT('%', '{{store_id}}', '%')
      AND h.staff_name LIKE CONCAT('%', '{{yuangong}}', '%')
      AND a.created_time >= '{{date_start}}'
      AND a.created_time <= '{{date_end}}'
) combined
GROUP BY `门店`, `员工`, `项目`, `分类`
ORDER BY `门店`, `员工`, `分类`, `项目`;
