-- 企微、普通微信、其他收入、非现金其他收款代码必须保持四种独立身份。
SELECT code,display_name,status,sort,version
FROM eb_cashier_v3_payment_method_config
WHERE code IN ('unionpay','dianping_voucher','douyin_voucher','enterprise_wechat',
  'bank_transfer','partner_collection','cash','other_income','third_party','other_collection','wechat','alipay')
ORDER BY sort,id;
SELECT payment_method,COUNT(*) AS fact_count
FROM eb_cashier_v3_payment_fact
WHERE payment_method IN ('wechat','enterprise_wechat','other_income','other_collection')
GROUP BY payment_method;
