-- 表、索引与业务金额一致性：最后两项应为0，不进行任何修复写入。
SHOW CREATE TABLE eb_cashier_v3_debt_cancellation;
SELECT COUNT(*) AS invalid_cancellation_amount FROM eb_cashier_v3_debt_cancellation
WHERE cancelled_debt_cents <= 0 OR original_debt_cents <> repaid_debt_cents + cancelled_debt_cents;
SELECT COUNT(*) AS inconsistent_debt_state FROM eb_cashier_v3_debt_cancellation c
LEFT JOIN eb_store_debt d ON d.id=c.debt_id
WHERE d.id IS NULL OR d.status<>3 OR ROUND(d.total_debt*100)<>c.original_debt_cents OR ROUND(d.repaid_debt*100)<>c.repaid_debt_cents;
