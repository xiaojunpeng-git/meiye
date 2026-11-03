-- 只读核对：007原渠道编号、010当前配置及历史事实数量；不改历史收款。
SELECT id,name FROM eb_cash_type WHERE id IN (1,2,3,4,5,6,7,8,9,10) ORDER BY id;
SELECT code,default_name,display_name,status,sort,version
FROM eb_cashier_v3_payment_method_config
WHERE code IN ('unionpay','dianping_voucher','douyin_voucher','enterprise_wechat',
  'bank_transfer','partner_collection','cash','other_income','third_party','other_collection','wechat','alipay')
ORDER BY sort,id;
SELECT payment_method,COUNT(*) AS fact_count
FROM eb_cashier_v3_payment_fact
WHERE payment_method IN ('wechat','enterprise_wechat','other_income','other_collection')
GROUP BY payment_method;
