-- MYDX-P1-10：仅注册菜单入口，不创建新增/编辑/删除/作废按钮权限，不自动授予旧角色。
-- ID交由当前库分配；唯一授权码防止重复安装。默认关闭由01迁移保证。
INSERT INTO `eb_system_menus` (`pid`,`type`,`icon`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT 0,1,'md-school','成长管理','admin',1,1,'/admin/growth','',1,'growth',1,'admin-growth','[]'
WHERE NOT EXISTS (SELECT 1 FROM `eb_system_menus` WHERE `unique_auth`='admin-growth');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'课程与知识','admin',1,1,'/admin/growth/content',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-content','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-content');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'学习任务','admin',1,1,'/admin/growth/tasks',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-tasks','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-tasks');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'培养计划','admin',1,1,'/admin/growth/plans',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-plans','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-plans');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'练习与考试','admin',1,1,'/admin/growth/exams',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-exams','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-exams');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'带教与实操','admin',1,1,'/admin/growth/practice',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-practice','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-practice');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'巡检整改','admin',1,1,'/admin/growth/inspection',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-inspection','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-inspection');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'成长档案与考评','admin',1,1,'/admin/growth/profiles',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-profiles','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-profiles');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'成长统计与工作台','admin',1,1,'/admin/growth/reports',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-reports','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-reports');
INSERT INTO `eb_system_menus` (`pid`,`type`,`menu_name`,`module`,`is_show`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`params`)
SELECT root.id,1,'成长设置','admin',1,1,'/admin/growth/settings',CAST(root.id AS CHAR),1,'growth',0,'admin-growth-settings','[]' FROM `eb_system_menus` root WHERE root.unique_auth='admin-growth' AND NOT EXISTS (SELECT 1 FROM `eb_system_menus` child WHERE child.unique_auth='admin-growth-settings');
