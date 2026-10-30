-- 只增加关闭状态的随行付配置定义；每个独立客户实例按授权单独执行。
-- 密钥不写入数据库或 Git。SUIXINGPAY_PRIVATE_KEY_FILE 指向站点外私钥。
-- 不修改既有富友配置与已发起支付订单，重跑不覆盖客户填入的参数。
INSERT INTO eb_system_config
    (is_store, menu_name, type, input_type, config_tab_id, parameter, upload_type,
     required, width, high, value, info, `desc`, sort, status)
SELECT 0, n.menu_name, 'text', 'input', t.config_tab_id, '', 0,
       '', 250, 0, n.initial_value, n.info, n.description, t.sort + 1, 1
FROM eb_system_config t
JOIN (
    SELECT 'suixingpay_pay_status' menu_name, '0' initial_value, '随行付支付状态' info,
           '与富友支付只能开启一种' description
    UNION ALL SELECT 'suixingpay_org_id', '""', '随行付机构号', '天阙开放平台 orgId'
    UNION ALL SELECT 'suixingpay_mno', '""', '随行付商户号', '本客户独立商户 mno'
    UNION ALL SELECT 'suixingpay_gateway_url', '""', '随行付接口地址', '随行付提供的正式 HTTPS 网关地址'
    UNION ALL SELECT 'suixingpay_platform_public_key', '""', '随行付平台公钥', '用于验证平台应答及支付回调，不是本商户公钥'
    UNION ALL SELECT 'suixingpay_sub_appid', '""', '会员微信小程序 AppID', '必须与本实例 routine_appId 一致'
) n
WHERE t.menu_name = 'fuyou_id' AND t.is_store = 0
  AND NOT EXISTS (SELECT 1 FROM eb_system_config c WHERE c.menu_name = n.menu_name AND c.is_store = 0);
