-- 会员端“我的服务”菜单：让统一图标成为持久化配置，并允许图标菜单不上传旧图片。
-- MySQL 5.7+；可重复执行。
UPDATE eb_system_group
SET fields = JSON_SET(
    JSON_ARRAY_APPEND(
        fields,
        '$',
        JSON_OBJECT('name', '统一图标', 'title', 'icon', 'type', 'input', 'param', '', 'required', FALSE)
    ),
    '$[1].required',
    FALSE
)
WHERE config_name = 'routine_my_menus'
  AND JSON_SEARCH(fields, 'one', 'icon', NULL, '$[*].title') IS NULL;

-- 兼容已经补过 icon 字段、但图片字段尚未改为可选的实例。
UPDATE eb_system_group
SET fields = JSON_SET(fields, '$[1].required', FALSE)
WHERE config_name = 'routine_my_menus'
  AND JSON_SEARCH(fields, 'one', 'icon', NULL, '$[*].title') IS NOT NULL
  AND JSON_EXTRACT(fields, '$[1].title') = '"pic"';
