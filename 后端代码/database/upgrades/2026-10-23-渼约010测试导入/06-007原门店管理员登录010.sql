-- 010 测试实例：把 007 一致性快照中 11 个有效门店管理员接入新门店端。
-- 原账号、原 bcrypt 密码及唯一门店任职保持不变；007/008/012 均不写入。
-- 门店管理员保留原店管理能力，旧数据录入（301050）不自动授予。
USE mohe_010_app;
SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION';

DROP PROCEDURE IF EXISTS mohe_010_app.codex_010_store_manager_preflight;
DELIMITER //
CREATE PROCEDURE mohe_010_app.codex_010_store_manager_preflight()
BEGIN
  -- 迁移只接受既定快照和未补录员工的 010 状态，避免覆盖后续真实账号编辑。
  IF (SELECT COUNT(*) FROM mohe_010_app.codex_010_migration_batch
      WHERE batch_key='010-linlin-20261023-refresh-1')<>1
     OR (SELECT COUNT(*) FROM mohe_010_refresh.eb_system_store_staff
         WHERE id IN (1,2,3,4,5,6,7,8,9,10,106) AND level=0 AND is_admin=1
           AND status=1 AND is_del=0 AND pwd REGEXP '^\\$2[aby]\\$[0-9][0-9]\\$')<>11
     OR (SELECT COUNT(DISTINCT phone) FROM mohe_010_refresh.eb_system_store_staff
         WHERE id IN (1,2,3,4,5,6,7,8,9,10,106))<>11
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_employee)<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_employee_internal_account)<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_staff_job_position)<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_job_position_channel_rule WHERE channel='store_v3')<>0
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_system_store_staff t
         JOIN mohe_010_refresh.eb_system_store_staff s ON s.id=t.id
         JOIN mohe_010_app.eb_system_store st ON st.id=t.store_id
         WHERE t.id IN (1,2,3,4,5,6,7,8,9,10,106)
           AND t.account='test010store' AND t.employee_id IS NULL
           AND t.store_id=s.store_id AND t.phone=s.phone
           AND t.status=1 AND t.is_del=0 AND st.is_del=0 AND st.is_show=1)<>11
     OR (SELECT COUNT(*) FROM mohe_010_app.eb_position WHERE id IN (1,2) AND status=1)<>2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 store manager preflight failed';
  END IF;
END//
DELIMITER ;
CALL mohe_010_app.codex_010_store_manager_preflight();
DROP PROCEDURE mohe_010_app.codex_010_store_manager_preflight;

START TRANSACTION;
-- 以旧门店任职 ID 作为员工 ID，保证迁移身份可回溯，且不碰会员 UID。
INSERT INTO mohe_010_app.eb_employee
  (id,name,phone,avatar,avatar_type,uid,status,status_version,
   employment_type_code,employment_type_version,auth_version,is_del,add_time,update_time)
SELECT s.id,s.staff_name,s.phone,
       COALESCE(NULLIF(s.avatar,''),'/static/images/staff/avatar_male.png'),1,
       NULL,1,0,'internal',1,1,0,s.add_time,UNIX_TIMESTAMP()
FROM mohe_010_refresh.eb_system_store_staff s
WHERE s.id IN (1,2,3,4,5,6,7,8,9,10,106);

-- 手机号是登录输入，不能写成内部账号；短账号沿用 007 原值。
INSERT INTO mohe_010_app.eb_employee_internal_account
  (employee_id,account,pwd,status,credential_version,is_del,add_time,update_time)
SELECT s.id,
       IF(s.account REGEXP '^1[0-9]{10}$',CONCAT('emp',s.id),s.account),
       s.pwd,1,1,0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM mohe_010_refresh.eb_system_store_staff s
WHERE s.id IN (1,2,3,4,5,6,7,8,9,10,106);

-- 替换先前仅供旧接口测试的临时账号，恢复 007 原账号和原密码散列。
UPDATE mohe_010_app.eb_system_store_staff t
JOIN mohe_010_refresh.eb_system_store_staff s ON s.id=t.id
SET t.employee_id=s.id,t.account=s.account,t.pwd=s.pwd
WHERE t.id IN (1,2,3,4,5,6,7,8,9,10,106);

-- 007 的这 11 人是各自门店的管理员；新岗位只在 010 授予原店管理入口。
UPDATE mohe_010_app.eb_position SET use_store=1 WHERE id IN (1,2);
INSERT INTO mohe_010_app.eb_job_position_channel_rule
  (position_id,channel,rules,status,version,add_time,update_time)
SELECT p.id,'store_v3',
       '301001,301010,301011,301012,301013,301014,301015,301016,301017,301018,301019,301002,301003,301004,301005,301020,301021,301008,301006,301030,301031,301032,301033,301034,301035,301036,301037,301007,301040,301041,301100,301101,301102,301103,301104,301105,301106,301107,301108,301109,301110,301120,301121,301122',
       1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM mohe_010_app.eb_position p WHERE p.id IN (1,2);

-- position=0 的旧超级管理员归入店长；其他人保留原店长/副店岗位。
INSERT INTO mohe_010_app.eb_staff_job_position
  (employee_id,store_id,staff_id,position_id,status,start_time,end_time,
   reason,is_del,request_token,operator_id,operator_name,add_time,update_time)
SELECT s.id,s.store_id,s.id,IF(s.position IN (1,2),s.position,1),1,
       s.add_time,0,'010导入007门店管理员',0,CONCAT('010-007-manager-',s.id),
       0,'010数据迁移',UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM mohe_010_refresh.eb_system_store_staff s
WHERE s.id IN (1,2,3,4,5,6,7,8,9,10,106);
COMMIT;

-- 验证原账号/哈希、唯一任职和岗位入口逐人相符；应为 11、11、11。
SELECT COUNT(*) AS restored_credentials
FROM mohe_010_app.eb_employee_internal_account a
JOIN mohe_010_refresh.eb_system_store_staff s ON s.id=a.employee_id
JOIN mohe_010_app.eb_system_store_staff t ON t.id=s.id
WHERE s.id IN (1,2,3,4,5,6,7,8,9,10,106)
  AND a.pwd=s.pwd AND t.pwd=s.pwd AND t.account=s.account
  AND t.employee_id=s.id AND a.status=1;
SELECT COUNT(*) AS unique_store_jobs
FROM mohe_010_app.eb_staff_job_position j
JOIN mohe_010_refresh.eb_system_store_staff s ON s.id=j.staff_id
WHERE s.id IN (1,2,3,4,5,6,7,8,9,10,106)
  AND j.employee_id=s.id AND j.store_id=s.store_id AND j.end_time=0;
SELECT COUNT(*) AS managers_with_store_v3_rule
FROM mohe_010_app.eb_staff_job_position j
JOIN mohe_010_app.eb_position p ON p.id=j.position_id
JOIN mohe_010_app.eb_job_position_channel_rule r
  ON r.position_id=p.id AND r.channel='store_v3' AND r.status=1
WHERE j.staff_id IN (1,2,3,4,5,6,7,8,9,10,106)
  AND j.status=1 AND j.is_del=0 AND p.use_store=1 AND p.status=1;
