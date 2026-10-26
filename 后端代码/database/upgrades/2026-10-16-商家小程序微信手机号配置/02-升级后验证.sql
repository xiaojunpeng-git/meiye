-- 只核对配置项存在与归属，不展示 AppSecret 值。
SELECT menu_name, is_store, config_tab_id, status
FROM eb_system_config
WHERE menu_name IN ('merchant_mini_appid', 'merchant_mini_appsecret')
ORDER BY menu_name;
