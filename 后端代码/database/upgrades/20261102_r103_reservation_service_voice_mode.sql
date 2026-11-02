-- 预约服务语音提醒的终端范围。默认手机端，仅补配置定义，不改历史预约。
INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'reservation_service_voice_mode', 'radio', '', t.`config_tab_id`, '1=>手机端\n2=>门店端\n3=>全部', 0, '', 0, 0,
       '1', '服务语音播报', '服务剩余五分钟和五秒时播报；手动结束不播报', 19, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_config` c
      WHERE c.`menu_name` = 'reservation_service_voice_mode' AND c.`is_store` = 0)
LIMIT 1;

-- 发布后才开始的服务允许自动结束；首次安装时间是切换保护线，幂等重跑不推进保护线。
INSERT INTO `eb_system_config`
(`is_store`, `menu_name`, `type`, `input_type`, `config_tab_id`, `parameter`, `upload_type`, `required`, `width`, `high`, `value`, `info`, `desc`, `sort`, `status`)
SELECT 0, 'reservation_auto_end_activated_at', 'text', '', t.`config_tab_id`, '', 0, '', 0, 0,
       CAST(UNIX_TIMESTAMP() AS CHAR), '预约自动结束启用时间', '仅内部定时任务读取，阻止历史服务在升级后被批量自动结束', 20, 1
FROM `eb_system_config` t
WHERE t.`menu_name` = 'station_open' AND t.`is_store` = 0
  AND NOT EXISTS (SELECT 1 FROM `eb_system_config` c
      WHERE c.`menu_name` = 'reservation_auto_end_activated_at' AND c.`is_store` = 0)
LIMIT 1;
