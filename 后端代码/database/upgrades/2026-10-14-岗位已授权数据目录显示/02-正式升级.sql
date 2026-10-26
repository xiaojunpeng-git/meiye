-- These existing, assignable report directories must be navigable when granted.
-- Do not grant roles, move reports, restore deleted menus, or expose hidden detail actions.
UPDATE eb_system_menus
SET is_show = 1
WHERE type = 1 AND auth_type = 1 AND is_del = 0 AND is_show_path = 0
  AND ((unique_auth = 'admin-report-sale' AND menu_path = '/report/sale')
    OR (unique_auth = 'admin-report-other-reports' AND menu_path = '/report/other-reports'));
