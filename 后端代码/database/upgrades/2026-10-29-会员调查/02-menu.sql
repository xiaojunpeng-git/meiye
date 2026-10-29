-- 客户 > 用户管理 > 会员调查。可重复执行，已有菜单时不会插入第二条。
INSERT INTO `eb_system_menus`
(`pid`, `type`, `icon`, `menu_name`, `module`, `controller`, `action`, `api_url`, `methods`, `params`, `sort`, `is_show`, `is_show_path`, `access`, `menu_path`, `path`, `auth_type`, `header`, `is_header`, `unique_auth`, `is_del`)
SELECT
  parent.`id`, 1, '', '会员调查', 'admin', 'user.MemberSurvey', 'index', '', '', '[]',
  6, 1, 0, 1, '/admin/user/survey',
  CONCAT_WS('/', NULLIF(parent.`path`, ''), parent.`id`), 1, 'user', 0, 'admin-user-survey', 0
FROM `eb_system_menus` AS parent
WHERE parent.`unique_auth` = 'admin-user'
  AND parent.`type` = 1
  AND parent.`is_del` = 0
  AND NOT EXISTS (
    SELECT 1 FROM `eb_system_menus` AS existing
    WHERE existing.`unique_auth` = 'admin-user-survey'
      AND existing.`type` = 1
      AND existing.`is_del` = 0
  )
LIMIT 1;
