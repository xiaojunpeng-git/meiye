-- R49: instance switch only; default off, idempotent, no catalogue or historical rights rewrites.
INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'cashier_ordinary_card_sale_edit_switch', 'radio', '', t.`config_tab_id`, '1=>开启\n0=>关闭', 0, '', 0, 0,
       '0', '普通卡销售可修改', '开启后普通卡销售可逐项修改总金额和次数，均不得低于原配置', 13, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_config` c
      WHERE c.`menu_name` = 'cashier_ordinary_card_sale_edit_switch' AND c.`is_store` = 0)
LIMIT 1;
