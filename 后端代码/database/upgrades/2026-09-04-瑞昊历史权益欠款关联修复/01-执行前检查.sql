-- 仅用于 xinruihao；只读预检。
-- MySQL 5.7 兼容，不使用 CTE。

DROP TEMPORARY TABLE IF EXISTS tmp_rh_card_debt_link_precheck;
CREATE TEMPORARY TABLE tmp_rh_card_debt_link_precheck AS
SELECT
  d.id AS debt_id,
  d.order_id AS current_order_id,
  MIN(h.oid) AS target_order_id,
  MIN(ci.id) AS target_card_cart_info_id,
  COUNT(DISTINCT h.oid) AS target_order_count,
  COUNT(DISTINCT ci.id) AS target_card_cart_count,
  COUNT(DISTINCT i.id) AS debt_item_count,
  d.total_debt,
  d.repaid_debt
FROM eb_store_debt d
JOIN eb_store_debt_item i ON i.debt_id=d.id
JOIN eb_user_card_holder h
  ON h.uid=d.uid
 AND h.product_id=i.product_id
 AND h.product_type=i.product_type
 AND h.is_del=0
JOIN eb_store_order_cart_info ci
  ON ci.oid=h.oid
 AND ci.product_id=i.product_id
 AND ci.product_type=i.product_type
WHERE d.debt_no LIKE 'RHD%'
  AND d.remark LIKE 'source_key=CARD:%'
  AND ci.cart_info LIKE CONCAT('%card_detail_id=', SUBSTRING_INDEX(d.remark,'CARD:',-1), '%')
GROUP BY d.id;

SELECT
  (SELECT COUNT(*) FROM eb_store_debt WHERE debt_no LIKE 'RHD%' AND remark LIKE 'source_key=CARD:%') AS source_card_debt_rows,
  COUNT(*) AS candidate_rows,
  SUM(target_order_count=1 AND target_card_cart_count=1 AND debt_item_count=1) AS exact_relation_rows,
  SUM(target_order_count<>1 OR target_card_cart_count<>1 OR debt_item_count<>1) AS ambiguous_relation_rows,
  SUM(total_debt<0 OR repaid_debt<0 OR total_debt<repaid_debt) AS non_positive_or_invalid_debt_rows,
  SUM(EXISTS(SELECT 1 FROM eb_store_debt d2 WHERE d2.order_id=p.target_order_id AND d2.id<>p.debt_id)) AS target_order_conflicts
FROM tmp_rh_card_debt_link_precheck p;
