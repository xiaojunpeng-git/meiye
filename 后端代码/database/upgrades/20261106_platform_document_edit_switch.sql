-- DATA-R5：平台单据总开关，默认开启；重复安装保留管理员选择，不修改业务事实。
INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'platform_document_edit_enabled', 'radio', '', t.`config_tab_id`, '1=>开启\n0=>关闭', 0, '', 0, 0,
       '1', '平台端允许修改单据', '关闭后平台端仅可查询、查看详情、打印和导出；门店端不受影响', 0, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_config` c WHERE c.`menu_name` = 'platform_document_edit_enabled' AND c.`is_store` = 0)
LIMIT 1;
