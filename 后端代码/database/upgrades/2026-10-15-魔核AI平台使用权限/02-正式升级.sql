-- Platform AI use is a role function under Settings. It grants no report data
-- scope and never grants model/metric configuration rights.
SET NAMES utf8mb4;
START TRANSACTION;

SET @mohe_ai_settings_id := (
  SELECT id FROM eb_system_menus
  WHERE type = 1 AND auth_type = 1 AND is_del = 0
    AND menu_path IN ('/setting', '/admin/setting')
  ORDER BY id LIMIT 1
);

-- A rerun must not silently re-grant a role whose AI use was later revoked.
SET @mohe_ai_use_already_active := (
  SELECT COUNT(*) FROM eb_system_menus
  WHERE unique_auth = 'mohe-ai-entry' AND menu_name = '魔核 AI 使用'
    AND pid = @mohe_ai_settings_id AND type = 1 AND auth_type = 2
    AND is_del = 0
);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT @mohe_ai_settings_id,1,'','魔核 AI 使用','admin','','','','','[]',0,1,0,1,'','',2,'',0,'mohe-ai-entry',0
FROM DUAL
WHERE @mohe_ai_settings_id IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE unique_auth = 'mohe-ai-entry' AND is_del = 0);

UPDATE eb_system_menus
SET pid = @mohe_ai_settings_id, menu_name = '魔核 AI 使用', type = 1,
    auth_type = 2, methods = '', is_show = 1
WHERE unique_auth = 'mohe-ai-entry' AND is_del = 0
  AND @mohe_ai_settings_id IS NOT NULL;

SET @mohe_ai_rule_id := (
  SELECT id FROM eb_system_menus
  WHERE unique_auth = 'mohe-ai-entry' AND type = 1 AND auth_type = 2
    AND is_del = 0 AND is_show = 1
  ORDER BY id LIMIT 1
);

-- Before this release, every scoped platform user could already use AI.
-- Preserve those existing platform/agent roles on activation; later role edits
-- can revoke this one function without changing any data-scope grants.
UPDATE eb_system_role
SET rules = CONCAT_WS(',', NULLIF(TRIM(BOTH ',' FROM COALESCE(rules, '')), ''), @mohe_ai_rule_id)
WHERE status = 1 AND type IN (0, 3) AND @mohe_ai_rule_id IS NOT NULL
  AND @mohe_ai_use_already_active = 0
  AND FIND_IN_SET(CAST(@mohe_ai_rule_id AS CHAR), COALESCE(rules, '')) = 0;

COMMIT;
