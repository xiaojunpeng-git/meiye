-- 前台收银是否允许欠款（默认关闭）
INSERT INTO `eb_system_config` (`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'cashier_debt_pay_switch', 'radio', '', t.`config_tab_id`, '1=>开启\n0=>关闭', 0, '', 0, 0, '0', '是否允许欠款', '关闭后收银台购物车和充值页面隐藏欠款按钮', 13, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_config` c WHERE c.`menu_name` = 'cashier_debt_pay_switch' AND c.`is_store` = 0)
LIMIT 1;
