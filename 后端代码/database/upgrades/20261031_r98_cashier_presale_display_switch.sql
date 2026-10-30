-- R98：新增售前显示开关，默认开启以保持各实例原有人员分配界面。
-- 重复执行不覆盖管理员选择；仅添加配置定义，不修改订单和历史售前事实。
INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'cashier_presale_switch', 'radio', '', t.`config_tab_id`, '1=>开启\n0=>关闭', 0, '', 0, 0,
       '1', '售前', '关闭后隐藏收银和订单管理业务分配中的售前选项及是否售前列，历史标记保留', 14, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (
      SELECT 1 FROM `eb_system_config` c
      WHERE c.`menu_name` = 'cashier_presale_switch' AND c.`is_store` = 0
  )
LIMIT 1;
