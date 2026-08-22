SET NAMES utf8mb4;

-- 仅回滚本次新增的九张客户菜单和“看板”节点，不删除既有会员菜单。
UPDATE eb_system_menus SET is_del=1, is_show=0
WHERE unique_auth='admin-customer-analytics-dashboard' OR unique_auth LIKE 'admin-customer-analytics-customer_%';
