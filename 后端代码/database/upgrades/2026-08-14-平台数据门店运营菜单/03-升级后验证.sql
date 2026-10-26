SELECT id, pid, menu_name, menu_path, unique_auth, is_show, is_del
FROM `eb_system_menus`
WHERE `unique_auth` = 'admin-report-store-operations' AND `is_del` = 0;
