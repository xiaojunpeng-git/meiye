-- 两张版本表应存在且初次迁移为空；历史原始归属行数量不应改变。
SHOW CREATE TABLE `eb_cashier_v3_refund_attribution_revision`;
SHOW CREATE TABLE `eb_cashier_v3_refund_attribution_revision_person`;
SELECT COUNT(*) AS revision_count FROM `eb_cashier_v3_refund_attribution_revision`;
SELECT COUNT(*) AS revision_person_count FROM `eb_cashier_v3_refund_attribution_revision_person`;
