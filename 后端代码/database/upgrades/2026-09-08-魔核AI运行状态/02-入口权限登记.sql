-- Registers grants only. No existing role or employee receives AI implicitly.
-- These are action permissions (auth_type=2), not navigable reporting pages.
INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','魔核 AI 入口','admin','','','','[]','[]',0,1,0,1,'','',2,'',0,'mohe-ai-entry',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE unique_auth='mohe-ai-entry' AND is_del=0);

INSERT INTO eb_system_menus
(`pid`,`type`,`icon`,`menu_name`,`module`,`controller`,`action`,`api_url`,`methods`,`params`,`sort`,`is_show`,`is_show_path`,`access`,`menu_path`,`path`,`auth_type`,`header`,`is_header`,`unique_auth`,`is_del`)
SELECT 0,1,'','魔核 AI 配置与自检','admin','','','','[]','[]',0,1,0,1,'','',2,'',0,'mohe-ai-config',0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM eb_system_menus WHERE unique_auth='mohe-ai-config' AND is_del=0);

-- Store rule 301009 and mobile rule 401011 live in their existing server catalogues.
-- Do not insert these ids into system_menus, or append them to existing岗位 grants.
