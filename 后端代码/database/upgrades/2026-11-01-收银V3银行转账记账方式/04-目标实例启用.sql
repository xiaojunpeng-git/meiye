-- 仅在产品经理验收并授权的目标实例执行；不迁移旧的“其他收款”。
UPDATE eb_cashier_v3_payment_method_config SET status=1, version=version+1,
updated_at=UNIX_TIMESTAMP() WHERE code='bank_transfer' AND status=0;
SELECT code, display_name, status FROM eb_cashier_v3_payment_method_config WHERE code='bank_transfer';
