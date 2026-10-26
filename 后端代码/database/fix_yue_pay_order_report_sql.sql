-- 余额扣卡订单明细报表 SQL（修复版）
-- 将本文件内容复制到 eb_salary_field 中 type=5 对应报表的 info 字段
-- 搜索项（eb_salary_search_field）建议：
--   has_recharge：input_type=4，info=全部,是,否
--   store_id：input_type=5（门店）

SELECT
    FROM_UNIXTIME(o.add_time, '%Y-%m-%d %H:%i:%s') AS `下单时间`,
    CASE
        WHEN o.uid = 0 THEN CONCAT('游客/', o.uid)
        ELSE CONCAT(
            IFNULL(NULLIF(u.nickname, ''), IFNULL(o.real_name, '')),
            '/', o.uid,
            IF(u.delete_time IS NOT NULL, '(已注销)', '')
        )
    END AS `用户信息`,
    TRIM(CONCAT(
        IFNULL(
            NULLIF(sp.store_name, ''),
            IF(
                c.cart_info LIKE '%"store_name":"%',
                SUBSTRING_INDEX(SUBSTRING_INDEX(c.cart_info, '"store_name":"', -1), '"', 1),
                IF(c.cart_info LIKE '%"title":"%',
                    SUBSTRING_INDEX(SUBSTRING_INDEX(c.cart_info, '"title":"', -1), '"', 1),
                    ''
                )
            )
        ),
        CASE
            WHEN sav.suk IS NOT NULL AND sav.suk <> '' AND sav.suk NOT IN ('默认', ' ')
            THEN CONCAT('(', sav.suk, ')')
            ELSE ''
        END
    )) AS `商品名称`,
    IFNULL(c.cart_num, '') AS `数量`,
    IF(
        c.cart_num > 0,
        ROUND(
            IFNULL(
                NULLIF(c.yue_pay_amount, 0),
                IF(o.pay_price > 0, o.yue_pay_price * c.pay_price / o.pay_price, 0)
            ) / c.cart_num,
            2
        ),
        IFNULL(
            NULLIF(c.yue_pay_amount, 0),
            IF(o.pay_price > 0, ROUND(o.yue_pay_price * c.pay_price / o.pay_price, 2), IFNULL(o.yue_pay_price, 0))
        )
    ) AS `单价`,
    IF(IFNULL(o.service_object, '') = '朋友', '朋友', '本人') AS `服务对象`,
    IFNULL(
        NULLIF(c.yue_pay_amount, 0),
        IF(o.pay_price > 0, ROUND(o.yue_pay_price * c.pay_price / o.pay_price, 2), IFNULL(o.yue_pay_price, 0))
    ) AS `付款金额`,
    0 AS `赠送金额`,
    CASE o.pay_type
        WHEN 'weixin' THEN '微信支付'
        WHEN 'yue' THEN '余额支付'
        WHEN 'offline' THEN '线下支付'
        WHEN 'alipay' THEN '支付宝支付'
        WHEN 'cash' THEN IFNULL(ct.name, '')
        WHEN 'combination' THEN '组合支付'
        ELSE '其他支付'
    END AS `付款方式`,
    IFNULL(hands.staffs, '') AS `手艺人`,
    IFNULL(sales.staffs, '') AS `销售人`,
    IFNULL(ss.name, '') AS `消费门店`,
    CASE
        WHEN o.is_del = 1 OR o.is_system_del = 1 THEN '已删除'
        WHEN o.is_user_del = 1 THEN '已取消'
        WHEN o.paid = 0 AND o.status = 0 THEN '待付款'
        WHEN o.paid = 1 AND o.status = 4 AND o.shipping_type IN (1, 3) AND o.refund_status = 0 THEN '部分发货'
        WHEN o.paid = 1 AND o.status = 5 AND o.shipping_type = 2 AND o.refund_status = 0 THEN '部分核销'
        WHEN o.paid = 1 AND o.refund_status = 1 THEN '申请退款'
        WHEN o.paid = 1 AND o.refund_status = 2 THEN '已退款'
        WHEN o.paid = 1 AND o.refund_status = 4 THEN '退款中'
        WHEN o.paid = 1 AND o.status = 0 AND o.shipping_type IN (1, 3, 4) AND o.refund_status = 0 THEN '未发货'
        WHEN o.paid = 1 AND o.status IN (0, 1) AND o.shipping_type = 2 AND o.refund_status = 0 THEN '未核销'
        WHEN o.paid = 1 AND o.status IN (1, 5) AND o.shipping_type IN (1, 3, 4) AND o.refund_status = 0 THEN '待收货'
        WHEN o.paid = 1 AND o.status = 2 AND o.refund_status = 0 THEN '待评价'
        WHEN o.paid = 1 AND o.status = 3 AND o.refund_status = 0 THEN '已完成'
        WHEN o.paid = 1 AND o.refund_status = 3 THEN '部分退款'
        ELSE '未知状态'
    END AS `订单状态`,
    '普通订单' AS `订单类型`,
    IFNULL(cs.name, '老客扣卡') AS `来源`,
    CASE
        WHEN IFNULL(o.is_gendan, 0) = 1 THEN '是'
        WHEN o.pid > 0 AND IFNULL(p.is_gendan, 0) = 1 THEN '是'
        ELSE '否'
    END AS `是否跟单`,
    o.id AS `订单ID`,
    o.order_id AS `订单号`,
    IFNULL(o.back_reason, '') AS `撤销原因`,
    IFNULL(o.link_id, 0) AS `关联ID`,
    IFNULL(o.link_order, 0) AS `关联订单ID`,
    IFNULL(
        CASE WHEN IFNULL(o.kua_store, 0) > 0 THEN ks.name ELSE ss.name END,
        ''
    ) AS `下单门店`,
    IFNULL(o.yue_pay_price, 0) AS `余额支付金额`,
    IF(o.uid > 0 AND r.has_recharge = 1, '是', '否') AS `当天是否有充值`
FROM eb_store_order o
LEFT JOIN eb_user u ON u.uid = o.uid
LEFT JOIN eb_system_store ss ON ss.id = o.store_id
LEFT JOIN eb_system_store ks ON ks.id = o.kua_store
LEFT JOIN eb_cash_source cs ON cs.id = o.source
LEFT JOIN eb_cash_type ct ON ct.id = o.cash_choose
LEFT JOIN eb_store_order_cart_info c ON c.oid = o.id AND IFNULL(c.cart_type, 0) = 0
LEFT JOIN eb_store_product sp ON sp.id = c.product_id
LEFT JOIN eb_store_product_attr_value sav ON sav.product_id = c.product_id AND sav.`unique` = c.sku_unique AND sav.type = 0
LEFT JOIN eb_store_order p ON p.id = o.pid
LEFT JOIN (
    SELECT
        order_id,
        GROUP_CONCAT(DISTINCT CONCAT(staff_name, IF(is_dian = 1, '(点)', '')) ORDER BY id SEPARATOR ',') AS staffs
    FROM eb_staff_yeji
    WHERE type = 3 AND status = 0
    GROUP BY order_id
) hands ON hands.order_id = o.id
LEFT JOIN (
    SELECT
        order_id,
        GROUP_CONCAT(DISTINCT staff_name ORDER BY id SEPARATOR ',') AS staffs
    FROM eb_staff_yeji
    WHERE type IN (1, 2) AND status = 0
    GROUP BY order_id
) sales ON sales.order_id = o.id
LEFT JOIN (
    SELECT
        r.uid,
        FROM_UNIXTIME(r.add_time, '%Y-%m-%d') AS recharge_date,
        1 AS has_recharge
    FROM eb_store_order r
    WHERE r.order_type = 1
      AND r.paid = 1
      AND r.is_system_del = 0
      AND r.refund_status = 0
      AND r.uid > 0
    GROUP BY r.uid, FROM_UNIXTIME(r.add_time, '%Y-%m-%d')
) r ON r.uid = o.uid AND o.uid > 0 AND r.recharge_date = FROM_UNIXTIME(o.add_time, '%Y-%m-%d')
WHERE o.paid = 1
  AND o.is_system_del = 0
  AND o.is_del = 0
  AND o.refund_status = 0
  AND IFNULL(o.is_auto, 0) <> 1
  AND o.pid <> -1
  AND (o.pid >= 0 OR o.pid = -2)
  AND o.order_type = 0
  AND IFNULL(o.cash_choose, 0) <> 9
  AND IFNULL(o.yue_pay_price, 0) > 0
  AND o.add_time >= UNIX_TIMESTAMP(CONCAT(DATE('{{date_start}}'), ' 00:00:00'))
  AND o.add_time <= UNIX_TIMESTAMP(CONCAT(DATE('{{date_end}}'), ' 23:59:59'))
  {{store_where_order}}
  AND IF(
    '{{has_recharge}}' = '' OR '{{has_recharge}}' = '全部',
    TRUE,
    IF('{{has_recharge}}' IN ('否', '0'), r.has_recharge IS NULL, TRUE)
  )
ORDER BY o.add_time DESC;
