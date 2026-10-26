-- 010 测试实例独立恢复 007 的 admin 平台登录。仅从 007 一致性快照读取该账号的已验证 bcrypt 散列。
-- 只写 mohe_010_app；不复制其他旧管理员、手机号、旧登录痕迹或外部密钥。
USE mohe_010_app;
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';
DROP PROCEDURE IF EXISTS mohe_010_app.codex_010_original_admin_preflight;
DELIMITER //
CREATE PROCEDURE mohe_010_app.codex_010_original_admin_preflight()
BEGIN
  IF (SELECT COUNT(*) FROM mohe_010_app.codex_010_migration_batch
      WHERE batch_key='010-linlin-20261023-refresh-1')<>1
     OR (SELECT COUNT(*) FROM mohe_010_refresh.eb_system_admin
         WHERE id=1 AND account='admin' AND admin_type=0 AND level=0
           AND status=1 AND is_del=0 AND pwd REGEXP '^\\$2[aby]\\$[0-9][0-9]\\$')<>1
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_admin
         WHERE id=1 OR account='admin')<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_admin
         WHERE account='test010admin' AND admin_type=0 AND level=0
           AND status=1 AND is_del=0)<>1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 original admin preflight failed';
  END IF;
END//
DELIMITER ;
CALL mohe_010_app.codex_010_original_admin_preflight();
DROP PROCEDURE mohe_010_app.codex_010_original_admin_preflight;

START TRANSACTION;
INSERT INTO mohe_010_app.eb_system_admin
  (id,employee_id,uid,account,admin_type,relation_id,head_pic,pwd,real_name,phone,
   roles,last_ip,last_time,add_time,login_count,level,status,is_way,is_del)
SELECT id,NULL,0,account,admin_type,0,'',pwd,'007管理员（010测试）','',
       roles,'',0,UNIX_TIMESTAMP(),0,level,1,0,0
FROM mohe_010_refresh.eb_system_admin
WHERE id=1 AND account='admin' AND admin_type=0 AND level=0 AND status=1 AND is_del=0;
COMMIT;
SELECT COUNT(*) AS restored_admin_count
FROM mohe_010_app.eb_system_admin a
JOIN mohe_010_refresh.eb_system_admin s ON s.id=a.id
WHERE a.id=1 AND a.account='admin' AND a.pwd=s.pwd AND a.level=0
  AND a.admin_type=0 AND a.status=1 AND a.is_del=0;
