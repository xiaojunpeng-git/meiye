-- 每个客户实例分别核对；先确认原始退款归属表及权限，避免越级执行。
SHOW TABLES LIKE 'eb_cashier_v3_refund_attribution';
SHOW TABLES LIKE 'eb_cashier_v3_refund_attribution_revision';
SHOW TABLES LIKE 'eb_cashier_v3_refund_attribution_revision_person';
