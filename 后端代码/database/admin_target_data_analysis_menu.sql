-- 总后台：目标管理菜单 + 目标数据分析页面
-- 执行前请确认 eb_system_menus 中 id 无冲突；如有冲突可去掉 id 字段改用自增

-- 1. 一级菜单：目标管理
INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
VALUES
(0, 1, 'md-analytics', '目标管理', 'admin', '', '', '', '', '[]', 84, 1, 0, 1, '/admin/target', '', 1, 'target', 1, 'admin-target', 0);

SET @target_menu_pid = LAST_INSERT_ID();

-- 2. 二级菜单：目标数据分析
INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
VALUES
(@target_menu_pid, 1, '', '目标数据分析', 'admin', 'target.StoreTarget', 'analysis', 'target/analysis', 'GET', '[]', 1, 1, 0, 1, '/admin/target/data_analysis', CONCAT(@target_menu_pid), 1, 'target', 0, 'admin-target-data-analysis', 0);

SET @target_data_analysis_id = LAST_INSERT_ID();

-- 3. （可选）接口权限节点，供细粒度授权
INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
VALUES
(@target_data_analysis_id, 1, '', '目标分析数据', 'admin', 'target.StoreTarget', 'analysis', 'target/analysis', 'GET', '[]', 0, 0, 0, 1, '', CONCAT(@target_menu_pid, '/', @target_data_analysis_id), 2, 'target', 0, 'admin-target-analysis', 0),
(@target_data_analysis_id, 1, '', '目标列表', 'admin', 'target.StoreTarget', 'list', 'target/list', 'GET', '[]', 0, 0, 0, 1, '', CONCAT(@target_menu_pid, '/', @target_data_analysis_id), 2, 'target', 0, 'admin-target-list', 0),
(@target_data_analysis_id, 1, '', '员工排行', 'admin', 'target.StoreTarget', 'ranking', 'target/ranking', 'GET', '[]', 0, 0, 0, 1, '', CONCAT(@target_menu_pid, '/', @target_data_analysis_id), 2, 'target', 0, 'admin-target-ranking', 0);

-- 4. 给平台超级管理员角色（id=1，请按实际 role id 调整）追加菜单权限
-- rules 字段为逗号分隔的 menu id
UPDATE `eb_system_role`
SET `rules` = TRIM(BOTH ',' FROM CONCAT(IFNULL(`rules`, ''), ',', @target_menu_pid, ',', @target_data_analysis_id))
WHERE `id` = 1 AND `type` = 0;

-- 执行后请：设置 -> 权限设置 -> 刷新缓存（或重启服务）
