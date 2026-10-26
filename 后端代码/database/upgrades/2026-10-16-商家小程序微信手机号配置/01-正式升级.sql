-- R10B: 商家端可独立于会员小程序配置微信手机号授权凭据。
-- 只增加空配置项；不改动现有 routine_appId / routine_appsecret。
INSERT INTO eb_system_config
    (is_store, menu_name, type, input_type, config_tab_id, parameter, upload_type,
     required, width, high, value, info, `desc`, sort, status)
SELECT 0, 'merchant_mini_appid', 'text', '', t.config_tab_id, '', 0,
       '', 250, 0, '""', '商家小程序 AppID', '仅商家端微信手机号授权使用', t.sort + 1, 1
FROM eb_system_config t
WHERE t.menu_name = 'routine_appId' AND t.is_store = 0
  AND NOT EXISTS (
      SELECT 1 FROM eb_system_config c
      WHERE c.menu_name = 'merchant_mini_appid' AND c.is_store = 0
  )
LIMIT 1;

INSERT INTO eb_system_config
    (is_store, menu_name, type, input_type, config_tab_id, parameter, upload_type,
     required, width, high, value, info, `desc`, sort, status)
SELECT 0, 'merchant_mini_appsecret', 'text', '', t.config_tab_id, '', 0,
       '', 250, 0, '""', '商家小程序 AppSecret', '留空表示不修改已保存的密钥', t.sort + 1, 1
FROM eb_system_config t
WHERE t.menu_name = 'routine_appsecret' AND t.is_store = 0
  AND NOT EXISTS (
      SELECT 1 FROM eb_system_config c
      WHERE c.menu_name = 'merchant_mini_appsecret' AND c.is_store = 0
  )
LIMIT 1;
