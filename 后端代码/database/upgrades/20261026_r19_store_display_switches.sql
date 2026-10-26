-- R19：门店端入口展示开关，默认开启以维持既有收银与订单列表行为。
-- 重复执行不覆盖各实例管理员已保存的开关值；仅新增配置定义，不修改业务事实。
INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'cashier_sales_attribution_switch', 'radio', '', t.`config_tab_id`, '1=>开启\n0=>关闭', 0, '', 0, 0,
       '1', '销售经理/导购', '关闭后隐藏收银归属入口及订单管理中的销售经理、导购列', 15, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (
      SELECT 1 FROM `eb_system_config` c
      WHERE c.`menu_name` = 'cashier_sales_attribution_switch' AND c.`is_store` = 0
  )
LIMIT 1;

INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'cashier_experience_switch', 'radio', '', t.`config_tab_id`, '1=>开启\n0=>关闭', 0, '', 0, 0,
       '1', '体验', '关闭后隐藏收银项目的体验按钮', 16, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (
      SELECT 1 FROM `eb_system_config` c
      WHERE c.`menu_name` = 'cashier_experience_switch' AND c.`is_store` = 0
  )
LIMIT 1;

-- 默认保留原入口；重复运行不得覆盖门店已保存的选择。
INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'cashier_craftsman_exclude_counts_switch', 'radio', '', t.`config_tab_id`, '1=>开启\n0=>关闭', 0, '', 0, 0,
       '1', '手艺人不算人数人次', '关闭后隐藏收银项目的“手艺人不算人数人次”按钮', 17, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (
      SELECT 1 FROM `eb_system_config` c
      WHERE c.`menu_name` = 'cashier_craftsman_exclude_counts_switch' AND c.`is_store` = 0
  )
LIMIT 1;
