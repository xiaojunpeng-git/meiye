-- detailYeji 接口等价查询
-- 参数: sum_type=2, staff_id=75, data=2026/06/01-2026/06/30, page=1, limit=20
-- sum_type=2 => 耗卡/劳动业绩 => staff_yeji.type = 3

-- ========== 1. 核心列表（与接口主数据一致） ==========
SELECT *
FROM eb_staff_yeji
WHERE status = 0
  AND staff_id = 75
  AND type = 3
  AND created_time BETWEEN '2026/06/01' AND '2026/06/30 23:59:59'
ORDER BY id DESC
LIMIT 20 OFFSET 0;


-- ========== 2. 带常用关联字段（推荐用这个核对接口） ==========
SELECT
    sy.id,
    sy.staff_id,
    CONCAT(sy.staff_name, '(', IF(sy.is_dian = 1, '点', '轮'), ')') AS staff_name,
    sy.yeji,
    sy.price,
    sy.is_dian,
    IF(sy.is_dian = 1, '点', '轮') AS dian,
    sy.link_id,
    sy.order_id,
    sy.goods_id,
    sy.store_id,
    sy.created_time,
    (
        SELECT so_hx.order_id
        FROM eb_store_order so_hx
        WHERE so_hx.order_type = 2
          AND so_hx.link_id = sy.link_id
        ORDER BY so_hx.id DESC
        LIMIT 1
    ) AS wx_order_id,
    CASE
        WHEN w.service_object IS NOT NULL AND w.service_object <> '' THEN w.service_object
        WHEN so.service_object IS NOT NULL AND so.service_object <> '' THEN so.service_object
        ELSE '本人'
    END AS service_object,
    (
        SELECT GROUP_CONCAT(
            CONCAT(m.staff_name, '(', IF(m.is_dian = 1, '点', '轮'), ')')
            ORDER BY m.id ASC SEPARATOR '、'
        )
        FROM eb_staff_yeji m
        WHERE m.link_id = sy.link_id
          AND m.goods_id = sy.goods_id
          AND m.type = 3
          AND m.status = 0
    ) AS labor_participant_names,
    u.real_name,
    u.phone,
    p.store_name AS product_name,
    p.image AS product_image,
    so.order_id AS main_order_id
FROM eb_staff_yeji sy
LEFT JOIN eb_store_order so ON so.id = sy.order_id
LEFT JOIN eb_store_order_writeoff w ON w.id = sy.link_id
LEFT JOIN eb_user u ON u.uid = so.uid
LEFT JOIN eb_store_product p ON p.id = sy.goods_id
WHERE sy.status = 0
  AND sy.staff_id = 75
  AND sy.type = 3
  AND sy.created_time BETWEEN '2026/06/01' AND '2026/06/30 23:59:59'
ORDER BY sy.id DESC
LIMIT 20 OFFSET 0;


-- ========== 3. 总条数 ==========
SELECT COUNT(*) AS total
FROM eb_staff_yeji
WHERE status = 0
  AND staff_id = 75
  AND type = 3
  AND created_time BETWEEN '2026/06/01' AND '2026/06/30 23:59:59';
