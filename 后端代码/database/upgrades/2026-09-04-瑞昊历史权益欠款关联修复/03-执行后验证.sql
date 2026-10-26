-- 仅用于 xinruihao；只读验证。

SELECT
  COUNT(*) AS positive_card_debt_rows,
  SUM(d.order_id=h.oid) AS linked_to_entitlement_order,
  SUM(i.order_id=h.oid AND i.cart_info_id=ci.id) AS linked_to_entitlement_line,
  SUM(o.debt_amount=d.total_debt AND o.repaid_debt_amount=d.repaid_debt) AS order_amount_synced,
  SUM(card_ci.debt_amount=d.total_debt AND card_ci.repaid_debt_amount=d.repaid_debt) AS cart_amount_synced
FROM eb_store_debt d
JOIN eb_store_debt_item i ON i.debt_id=d.id
JOIN eb_user_card_holder h
  ON h.uid=d.uid AND h.product_id=i.product_id AND h.product_type=i.product_type AND h.is_del=0
JOIN eb_store_order_cart_info ci
  ON ci.oid=h.oid AND ci.product_id=i.product_id AND ci.product_type=i.product_type
JOIN eb_store_order o ON o.id=h.oid
JOIN eb_store_order_cart_info card_ci ON card_ci.id=i.cart_info_id
WHERE d.debt_no LIKE 'RHD%'
  AND d.remark LIKE 'source_key=CARD:%'
  AND d.total_debt>=0
  AND d.repaid_debt>=0
  AND d.total_debt>=d.repaid_debt
  AND ci.cart_info LIKE CONCAT('%card_detail_id=', SUBSTRING_INDEX(d.remark,'CARD:',-1), '%');

-- 样本：用户反馈的 3980 健康瘦套餐应返回同一权益来源订单和欠款金额。
SELECT d.debt_no,d.total_debt,d.repaid_debt,o.order_id AS entitlement_order_no,
       h.card_name,h.card_no,ci.debt_amount AS entitlement_card_line_debt
FROM eb_store_debt d
JOIN eb_store_debt_item i ON i.debt_id=d.id
JOIN eb_user_card_holder h ON h.uid=d.uid AND h.product_id=i.product_id AND h.product_type=i.product_type AND h.is_del=0
JOIN eb_store_order o ON o.id=h.oid
JOIN eb_store_order_cart_info ci ON ci.id=i.cart_info_id
WHERE d.remark='source_key=CARD:770091718518';
