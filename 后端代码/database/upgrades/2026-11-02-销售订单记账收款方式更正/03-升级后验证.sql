-- 应返回 2；正式收款和经营事实金额不受迁移影响。
SELECT COUNT(*) AS correction_tables_exist FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
  'eb_cashier_v3_payment_method_correction','eb_cashier_v3_payment_method_current'
);

-- 应返回 0；同一命令或操作号绝不能写出两笔更正。
SELECT COUNT(*) AS duplicate_operations FROM (
  SELECT tenant_id,command_idempotency_key FROM eb_cashier_v3_payment_method_correction
  GROUP BY tenant_id,command_idempotency_key HAVING COUNT(*) > 1
) AS duplicate_commands;
