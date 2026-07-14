-- 门店商品销售汇总报表（产品/卡项/项目）
-- 用途：复制 SQL 到 eb_salary_field（type=5, info 字段）；列定义见文末说明
--
-- 表头：门店、分类、名称、销售业绩、销售数量、销售人数
-- 筛选：门店(store_id 固定)、分类(category)、名称(name)、下单时间(date 固定)
-- 口径：普通订单 order_type=0；现金业绩=有效现金支付（排除旧卡录入 cash_choose=9、组合支付中的旧卡/余额明细）

SELECT
    `门店`,
    `分类`,
    `名称`,
    ROUND(SUM(`现金业绩`), 2) AS `销售业绩`,
    SUM(`销售数量`) AS `销售数量`,
    COUNT(DISTINCT `客户标识`) AS `销售人数`
FROM (
    SELECT
        ss.name AS `门店`,
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
            '未分类'
        ) AS `分类`,
        IFNULL(NULLIF(parent.store_name, ''), cp.store_name) AS `名称`,
        CASE
            WHEN o.cash_choose = 9 THEN 0
            WHEN o.pay_type = 'combination' THEN GREATEST(
                0,
                LEAST(
                    IFNULL(
                        NULLIF(c.cash_pay_amount, 0),
                        IF(
                            o.pay_price > 0,
                            ROUND(o.cash_pay_price * c.pay_price / o.pay_price, 2),
                            IFNULL(o.cash_pay_price, 0)
                        )
                    ),
                    o.pay_price
                    - IFNULL(o.yue_pay_price, 0)
                    - IFNULL(
                        (
                            SELECT SUM(co.price)
                            FROM eb_combination_order co
                            WHERE co.order_id = o.id
                              AND co.cash_choose = 9
                        ),
                        0
                    )
                )
            )
            ELSE IFNULL(
                NULLIF(c.cash_pay_amount, 0),
                IF(
                    o.pay_price > 0,
                    ROUND(o.cash_pay_price * c.pay_price / o.pay_price, 2),
                    IFNULL(o.cash_pay_price, 0)
                )
            )
        END AS `现金业绩`,
        IFNULL(c.cart_num, 0) AS `销售数量`,
        CASE
            WHEN o.uid = 0 OR IFNULL(o.service_object, '') = '朋友' THEN CONCAT('g_', o.id, '_', c.id)
            ELSE CONCAT(DATE(FROM_UNIXTIME(o.add_time)), '_', o.uid)
        END AS `客户标识`
    FROM eb_store_order o
    INNER JOIN eb_store_order_cart_info c
        ON c.oid = o.id
        AND IFNULL(c.cart_type, 0) = 0
    INNER JOIN eb_store_product cp
        ON cp.id = c.product_id
    LEFT JOIN eb_store_product parent
        ON parent.id = cp.pid
        AND cp.pid > 0
    INNER JOIN eb_system_store ss
        ON ss.id = o.store_id
        AND ss.is_del = 0
    WHERE o.paid = 1
      AND o.is_system_del = 0
      AND o.is_del = 0
      AND o.refund_status IN (0, 3)
      AND IFNULL(o.is_auto, 0) <> 1
      AND (o.pid IN (0, -2) OR o.pid > 0)
      AND o.order_type = 0
      AND cp.product_type IN (0, 5, 6)
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
      )
      AND o.add_time >= UNIX_TIMESTAMP('{{date_start}}')
      AND o.add_time <= UNIX_TIMESTAMP('{{date_end}}')
      {{store_where_order}}
      AND (
          '{{category}}' = ''
          OR IFNULL(
              (
                  SELECT GROUP_CONCAT(DISTINCT pct.cate_name ORDER BY pct.sort DESC, pct.id SEPARATOR ',')
                  FROM eb_store_product_category pct
                  WHERE IFNULL(IFNULL(NULLIF(parent.cate_id, ''), cp.cate_id), '') <> ''
                    AND FIND_IN_SET(
                        pct.id,
                        REPLACE(IFNULL(NULLIF(parent.cate_id, ''), cp.cate_id), ' ', '')
                    )
              ),
              '未分类'
          ) LIKE CONCAT('%', '{{category}}', '%')
      )
      AND IFNULL(NULLIF(parent.store_name, ''), cp.store_name) LIKE CONCAT('%', '{{name}}', '%')
      AND IFNULL(NULLIF(parent.store_name, ''), cp.store_name) <> ''
) sales_detail
GROUP BY `门店`, `分类`, `名称`
ORDER BY `门店`, `分类`, `名称`;

-- ============================================================
-- 配置说明（按需执行）
-- ============================================================
-- 1) eb_salary_table：新建报表，记下 id 为 {table_id}
--
-- 2) eb_salary_field.type=5 存上面 SQL（table_ids = {table_id}）
--
-- 3) eb_salary_field 列配置（type<>5，key 与 SQL 别名一致）：
--    store / 门店
--    category / 分类
--    product_name / 名称
--    sales_amount / 销售业绩  (is_count=1)
--    sales_qty / 销售数量     (is_count=1)
--    sales_customers / 销售人数 (is_count=1)
--
-- 4) eb_salary_search_field 自定义搜索项（门店、日期区间为系统固定项）：
--    key=category, name=分类, input_type=1
--    key=name,     name=名称, input_type=1
--
-- 口径说明：
-- - 销售业绩：行级 cash_pay_amount（无则按订单 cash_pay_price 分摊），排除旧卡录入
-- - 销售数量：cart_num 合计
-- - 销售人数：同一会员同一天计 1 人；游客/朋友按订单行计 1 人（与门店销售分析一致）
-- - 商品范围：product_type 0产品 / 5卡项 / 6项目
