-- 仅用于 010 的旧业务目录与非敏感配置。先执行 01-业务快照导入.sql。
-- 007 快照只读；不复制旧管理员账号、密码和外部服务密钥。
USE mohe_010_app;
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
DROP PROCEDURE IF EXISTS mohe_010_app.codex_010_catalog_preflight;
DELIMITER //
CREATE PROCEDURE mohe_010_app.codex_010_catalog_preflight()
BEGIN
  IF (SELECT COUNT(*) FROM mohe_010_app.codex_010_migration_batch
      WHERE batch_key='010-linlin-20261023-refresh-1')<>1
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_menus)<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_role)<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_config)<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_config_tab)<>0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 catalog preflight failed';
  END IF;
END//
DELIMITER ;
CALL mohe_010_app.codex_010_catalog_preflight();
DROP PROCEDURE mohe_010_app.codex_010_catalog_preflight;

DROP PROCEDURE IF EXISTS mohe_010_app.codex_010_catalog_verify;
DELIMITER //
CREATE PROCEDURE mohe_010_app.codex_010_catalog_verify()
BEGIN
  IF (SELECT COUNT(*) FROM mohe_010_app.eb_system_menus)<>1630
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_role)<>27
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_config)<>365
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_config_tab)<>60
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_admin)<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_config
         WHERE menu_name='site_url' AND value='https://010.cc3798.com')<>1
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_config
         WHERE menu_name REGEXP '(app.?secret|token|(^|_)key$|accesskey|password|client_cert|client_key|private_key|api_key)'
           AND value<>'')<>0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 catalog verification failed';
  END IF;
END//
DELIMITER ;

START TRANSACTION;
INSERT INTO mohe_010_app.eb_system_menus
SELECT * FROM mohe_010_refresh.eb_system_menus;
INSERT INTO mohe_010_app.eb_system_role
  (id,type,relation_id,role_name,rules,cashier_rules,mall_rules,level,status,add_time)
SELECT id,type,relation_id,role_name,rules,cashier_rules,mall_rules,level,status,add_time
FROM mohe_010_refresh.eb_system_role;
INSERT INTO mohe_010_app.eb_system_config_tab
SELECT * FROM mohe_010_refresh.eb_system_config_tab;
INSERT INTO mohe_010_app.eb_system_config
  (id,is_store,menu_name,type,input_type,config_tab_id,parameter,upload_type,
   required,width,high,value,info,`desc`,sort,status)
SELECT id,is_store,menu_name,type,input_type,config_tab_id,parameter,upload_type,
       required,width,high,
       CASE
         WHEN menu_name='site_url' THEN 'https://010.cc3798.com'
         WHEN menu_name IN ('site_close','close_system') THEN '0'
         WHEN menu_name REGEXP '(pay.*(open|status)|recharge_switch|sms_type|erp_open|create_wechat_user|pay_success_printing_switch|store_pay_success_printing_switch|station_open|dada_delivery_status|uu_delivery_status)'
           THEN '0'
         WHEN menu_name REGEXP '(wechat|routine_app|pay_|alipay|sms_|api|key|secret|token|cert|password|mchid|client_id|develop_id|terminal_number|storage|upload|qiniu|tengxun|jd_|hw_|ty_|dada|uupt|fey_|printing|jst_|erp_|customer_url|customer_phone)'
           THEN ''
         ELSE value
       END,
       info,`desc`,sort,status
FROM mohe_010_refresh.eb_system_config;
CALL mohe_010_app.codex_010_catalog_verify();
COMMIT;
DROP PROCEDURE mohe_010_app.codex_010_catalog_verify;
