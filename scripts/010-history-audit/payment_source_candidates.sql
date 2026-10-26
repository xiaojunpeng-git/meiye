-- Read-only candidate inventory. Original 007 snapshot is immutable; only 010 is a future target.
-- Parent payments, upgraded rights and transferred ownership need separate lineage review.
SELECT t.id,t.uid,t.pid,t.type,t.order_type,t.pay_type,t.pay_price,t.cash_pay_price,
 t.yue_pay_price,t.debt_amount,t.paid,t.refund_status,t.terminal_action,
 t.paid_ben_amount,t.paid_give_amount,t.paid_balance_ready,
 COALESCE(m.target_uid,0) expected_uid,
 EXISTS(SELECT 1 FROM mohe_010_app.eb_store_order c WHERE c.pid=t.id) has_children,
 EXISTS(SELECT 1 FROM mohe_010_refresh.eb_combination_order c WHERE c.order_id=s.id AND c.pay_sub_type='card_upgrade') has_upgrade_credit,
 t.card_upgrade_use_oid,
 EXISTS(SELECT 1 FROM mohe_010_refresh.eb_store_order_status st WHERE st.oid=s.id AND st.change_type='card_transfer') has_transfer
FROM mohe_010_refresh.eb_store_order s
JOIN mohe_010_app.eb_store_order t ON t.id=s.id
LEFT JOIN mohe_010_app.codex_010_member_map m ON m.source_uid=s.uid
WHERE s.yue_pay_price>0
ORDER BY t.id;
