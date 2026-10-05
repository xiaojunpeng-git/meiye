-- 目标实例执行后只读验证；异常计数均应为0。
SELECT COUNT(*) AS restoration_without_cancellation
FROM `eb_cashier_v3_debt_restoration` r
LEFT JOIN `eb_cashier_v3_debt_cancellation` c ON c.cancellation_id = r.cancellation_id
WHERE c.id IS NULL;

SELECT COUNT(*) AS restoration_identity_mismatch
FROM `eb_cashier_v3_debt_restoration` r
JOIN `eb_cashier_v3_debt_cancellation` c ON c.cancellation_id = r.cancellation_id
WHERE r.debt_id <> c.debt_id OR r.tenant_id <> c.tenant_id
   OR r.store_id <> c.store_id OR r.member_id <> c.member_id
   OR r.restored_debt_cents <> c.cancelled_debt_cents;

SELECT COUNT(*) AS cancelled_debt_latest_already_restored
FROM `eb_store_debt` d
JOIN `eb_cashier_v3_debt_cancellation` c ON c.debt_id = d.id
  AND c.id = (SELECT MAX(c2.id) FROM `eb_cashier_v3_debt_cancellation` c2 WHERE c2.debt_id = d.id)
JOIN `eb_cashier_v3_debt_restoration` r ON r.cancellation_id = c.cancellation_id
WHERE d.status = 3;

SELECT COUNT(*) AS restored_debt_latest_not_reversed
FROM `eb_store_debt` d
JOIN `eb_cashier_v3_debt_cancellation` c ON c.debt_id = d.id
  AND c.id = (SELECT MAX(c2.id) FROM `eb_cashier_v3_debt_cancellation` c2 WHERE c2.debt_id = d.id)
LEFT JOIN `eb_cashier_v3_debt_restoration` r ON r.cancellation_id = c.cancellation_id
WHERE d.status = 0 AND r.id IS NULL;
