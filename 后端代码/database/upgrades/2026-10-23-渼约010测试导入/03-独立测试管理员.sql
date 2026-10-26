-- 在同一个 mysql 会话中先 SET @codex_010_admin_hash 为新生成的 bcrypt 散列。
-- 密码明文不放入脚本、SQL 日志或 007/008 数据库；只允许 010 测试实例。
USE mohe_010_app;
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
DROP PROCEDURE IF EXISTS mohe_010_app.codex_010_admin_preflight;
DELIMITER //
CREATE PROCEDURE mohe_010_app.codex_010_admin_preflight()
BEGIN
  IF (SELECT COUNT(*) FROM mohe_010_app.codex_010_migration_batch
      WHERE batch_key='010-linlin-20261023-refresh-1')<>1
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_menus)<>1630
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_admin)<>0
     OR @codex_010_admin_hash IS NULL
     OR @codex_010_admin_hash NOT REGEXP '^\\$2[aby]\\$[0-9][0-9]\\$' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 test admin preflight failed';
  END IF;
END//
DELIMITER ;
CALL mohe_010_app.codex_010_admin_preflight();
DROP PROCEDURE mohe_010_app.codex_010_admin_preflight;

START TRANSACTION;
INSERT INTO mohe_010_app.eb_system_admin
  (employee_id,uid,account,admin_type,relation_id,head_pic,pwd,real_name,phone,
   roles,last_ip,last_time,add_time,login_count,level,status,is_way,is_del)
VALUES
  (NULL,0,'test010admin',0,0,'',@codex_010_admin_hash,'010测试管理员','',
   '','',0,UNIX_TIMESTAMP(),0,0,1,0,0);
COMMIT;
SELECT COUNT(*) AS created_010_test_admin FROM mohe_010_app.eb_system_admin
WHERE account='test010admin' AND admin_type=0 AND level=0 AND status=1 AND is_del=0;
