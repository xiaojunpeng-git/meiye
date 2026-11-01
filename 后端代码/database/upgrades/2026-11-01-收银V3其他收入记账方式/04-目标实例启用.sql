-- 仅对明确授权目标010执行，先备份配置；历史记录不靠改名或此配置批量重分类。
START TRANSACTION;
UPDATE eb_cashier_v3_payment_method_config SET status=1,version=version+1,updated_at=UNIX_TIMESTAMP()
WHERE code='other_income' AND status=0;
UPDATE eb_cashier_v3_payment_method_config SET status=0,version=version+1,updated_at=UNIX_TIMESTAMP()
WHERE code='other_collection' AND status=1;
COMMIT;
