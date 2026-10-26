-- 必须为 0：充值主单业务日全量回填。
SELECT COUNT(*) AS recharge_business_date_missing FROM eb_user_recharge WHERE business_date IS NULL;

-- 必须为 1：审计表存在。
SELECT COUNT(*) AS attribution_audit_table_exists FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'eb_cashier_v3_order_attribution_change';

-- 人工核对最近更正的单号、字段前后值、操作人和真实发生时间。
SELECT record_type, record_no_snapshot, field_key, old_value, new_value,
       operator_id, operator_name_snapshot, FROM_UNIXTIME(occurred_at) AS occurred_at_db_timezone
FROM eb_cashier_v3_order_attribution_change ORDER BY id DESC LIMIT 30;
