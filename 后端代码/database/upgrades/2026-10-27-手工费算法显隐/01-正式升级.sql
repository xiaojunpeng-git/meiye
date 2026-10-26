-- R37：选项只控制显示；默认可修改。幂等补定义，保留已有值及全部金额事实。
INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'cashier_labor_fee_mode', 'radio', '', t.`config_tab_id`, '0=>可修改\n1=>计算法', 0, '', 0, 0,
       '0', '手工费算法', '计算法仅隐藏收银、全部订单及消费订单中的手工费输入和显示，保留原有金额和计算口径', 17, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_config` c
      WHERE c.`menu_name` = 'cashier_labor_fee_mode' AND c.`is_store` = 0)
LIMIT 1;
