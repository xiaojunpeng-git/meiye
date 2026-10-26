-- upgrade_key: 20260827-001-customer-unconsumed-query-performance
SET NAMES utf8mb4;

SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND INDEX_NAME IN ('idx_unconsumed_store_state', 'idx_unconsumed_oid_state')
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

SELECT upgrade_key, executed_at, executed_by, result_note
FROM eb_database_upgrade_log
WHERE upgrade_key = '20260827-001-customer-unconsumed-query-performance';

-- Use representative store and order ids from the target database before
-- comparing key/rows with the pre-upgrade plan.
EXPLAIN SELECT h.uid,h.oid,h.store_id,h.write_surplus_times
FROM eb_user_card_holder h
JOIN eb_store_order o ON o.id=h.oid
WHERE h.store_id IN (0)
  AND h.is_del=0 AND h.write_surplus_times>0
  AND o.paid=1 AND o.is_del=0 AND o.is_system_del=0 AND o.refund_status=0;

EXPLAIN SELECT id,oid,product_id,pay_price,write_times,write_surplus_times
FROM eb_store_order_cart_info
WHERE oid IN (0) AND write_surplus_times>0 AND cart_type=2 AND product_type=6 AND is_writeoff=0;
