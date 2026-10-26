-- Read-only post-apply reconciliation; no production rows are changed here.
SELECT repair_kind, COUNT(*) AS repaired_sources,
       COUNT(DISTINCT evidence_hash) AS preview_versions,
       COUNT(DISTINCT backup_ref) AS backup_versions
FROM mig_rh_20261013_category_and_card_repair
GROUP BY repair_kind;

SELECT id, store_name, cate_id
FROM eb_store_product
WHERE id IN (56952, 57056, 57264, 64128, 603822)
ORDER BY id;

SELECT a.source_id AS sale_fact_id,
       s.order_no_snapshot,
       s.sale_amount_cents,
       s.sale_amount_cents - s.debt_amount_cents AS original_cash_cents,
       COUNT(DISTINCT c.id) AS category_fact_count,
       COUNT(DISTINCT i.id) AS item_fact_count,
       COALESCE((SELECT SUM(c2.sale_amount_cents)
                 FROM eb_cashier_v3_card_sale_category_allocation_fact c2
                 WHERE c2.tenant_id='0' AND c2.sale_fact_id=a.source_id AND c2.status='effective'),0)
           AS category_sale_cents,
       COALESCE((SELECT SUM(i2.sale_amount_cents)
                 FROM eb_cashier_v3_card_sale_item_allocation_fact i2
                 WHERE i2.tenant_id='0' AND i2.sale_fact_id=a.source_id AND i2.status='effective'),0)
           AS item_sale_cents,
       COALESCE((SELECT SUM(c3.cash_performance_amount_cents)
                 FROM eb_cashier_v3_card_sale_category_allocation_fact c3
                 WHERE c3.tenant_id='0' AND c3.sale_fact_id=a.source_id AND c3.status='effective'),0)
           AS category_cash_cents,
       COALESCE((SELECT SUM(i3.cash_performance_amount_cents)
                 FROM eb_cashier_v3_card_sale_item_allocation_fact i3
                 WHERE i3.tenant_id='0' AND i3.sale_fact_id=a.source_id AND i3.status='effective'),0)
           AS item_cash_cents
FROM mig_rh_20261013_category_and_card_repair a
JOIN eb_cashier_v3_sale_fact s ON s.fact_id=a.source_id AND s.tenant_id='0'
LEFT JOIN eb_cashier_v3_card_sale_category_allocation_fact c
       ON c.sale_fact_id=a.source_id AND c.tenant_id='0' AND c.status='effective'
LEFT JOIN eb_cashier_v3_card_sale_item_allocation_fact i
       ON i.sale_fact_id=a.source_id AND i.tenant_id='0' AND i.status='effective'
WHERE a.repair_kind='card_sale'
GROUP BY a.source_id,s.order_no_snapshot,s.sale_amount_cents,s.debt_amount_cents
ORDER BY s.order_no_snapshot,a.source_id;
