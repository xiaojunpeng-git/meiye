-- 仅在产品经理单独授权010生产库迁移后执行；先核实01结果并备份配置。
-- 旧事实只按渠道代码归属。显示名/顺序/启停只改变当前结账及对账展示。
SET NAMES utf8mb4;
START TRANSACTION;
UPDATE eb_cashier_v3_payment_method_config
SET display_name=CASE code
    WHEN 'unionpay' THEN '银联扫码'
    WHEN 'enterprise_wechat' THEN '企微收款'
    WHEN 'other_income' THEN '其他收款'
    WHEN 'cash' THEN '现金收款'
    ELSE display_name END,
  status=CASE WHEN code IN ('wechat','alipay','other_collection') THEN 0 ELSE 1 END,
  sort=CASE code
    WHEN 'unionpay' THEN 10 WHEN 'dianping_voucher' THEN 20
    WHEN 'douyin_voucher' THEN 30 WHEN 'enterprise_wechat' THEN 40
    WHEN 'bank_transfer' THEN 50 WHEN 'partner_collection' THEN 60
    WHEN 'cash' THEN 70 WHEN 'other_income' THEN 80
    WHEN 'third_party' THEN 90 ELSE sort END,
  version=version+1,updated_at=UNIX_TIMESTAMP()
WHERE code IN ('unionpay','dianping_voucher','douyin_voucher','enterprise_wechat',
  'bank_transfer','partner_collection','cash','other_income','third_party','other_collection','wechat','alipay');
COMMIT;
