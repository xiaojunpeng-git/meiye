-- 预期：has_icon = 1，pic_optional = 1。
SELECT
    JSON_SEARCH(fields, 'one', 'icon', NULL, '$[*].title') IS NOT NULL AS has_icon,
    JSON_EXTRACT(fields, '$[1].required') = FALSE AS pic_optional
FROM eb_system_group
WHERE config_name = 'routine_my_menus';
