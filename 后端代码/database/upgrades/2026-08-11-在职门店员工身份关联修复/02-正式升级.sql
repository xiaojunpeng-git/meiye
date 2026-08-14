-- upgrade_key: 20260811-003-active-store-staff-employee-identity-repair
-- Run only after 01 returns PRECHECK_OK. MySQL 5.6 compatible.
SET NAMES utf8mb4;
SET @sier_key := '20260811-003-active-store-staff-employee-identity-repair';
SET @sier_now := UNIX_TIMESTAMP();

-- A historical staff account is not a phone identity. NULL permits multiple
-- account-only employee records without inventing a mobile number.
ALTER TABLE eb_employee
  MODIFY COLUMN phone char(11) NULL DEFAULT NULL COMMENT '标准11位手机号；未知时为NULL，全局唯一';

DROP TEMPORARY TABLE IF EXISTS tmp_sier_candidate;
CREATE TEMPORARY TABLE tmp_sier_candidate (
  staff_id int(10) unsigned NOT NULL,
  previous_employee_id int(10) unsigned DEFAULT NULL,
  source_type varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  employee_name varchar(64) NOT NULL,
  employee_phone char(11) DEFAULT NULL,
  employee_uid int(10) unsigned DEFAULT NULL,
  default_internal tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (staff_id)
) ENGINE=InnoDB;

INSERT INTO tmp_sier_candidate
  (staff_id,previous_employee_id,source_type,source_hash,employee_name,employee_phone,employee_uid,default_internal)
SELECT staff.id, staff.employee_id, 'staff_uid', SHA2(CONCAT('staff_uid:',staff.uid),256),
  IF(TRIM(staff.staff_name)='',CONCAT('历史任职#',staff.id),LEFT(TRIM(staff.staff_name),64)),
  NULL, staff.uid,
  IF(COALESCE(staff.is_hezuofang,0)=0 AND COALESCE(staff.is_fencheng,0)=0,1,0)
FROM eb_system_store_staff staff
LEFT JOIN eb_employee current_employee ON current_employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR current_employee.id IS NULL
       OR current_employee.status<>1 OR current_employee.is_del<>0)
  AND staff.uid>0
  AND NOT EXISTS (SELECT 1 FROM eb_employee employee WHERE employee.uid=staff.uid)
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_store_staff peer
    WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.uid=staff.uid
  );

INSERT INTO tmp_sier_candidate
  (staff_id,previous_employee_id,source_type,source_hash,employee_name,employee_phone,employee_uid,default_internal)
SELECT staff.id, staff.employee_id, 'staff_account', SHA2(CONCAT('staff_account:',staff.account),256),
  IF(TRIM(staff.staff_name)='',CONCAT('历史任职#',staff.id),LEFT(TRIM(staff.staff_name),64)),
  NULL, NULL,
  IF(COALESCE(staff.is_hezuofang,0)=0 AND COALESCE(staff.is_fencheng,0)=0,1,0)
FROM eb_system_store_staff staff
LEFT JOIN eb_employee current_employee ON current_employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR current_employee.id IS NULL
       OR current_employee.status<>1 OR current_employee.is_del<>0)
  AND NOT (
    staff.uid>0
    AND NOT EXISTS (SELECT 1 FROM eb_employee employee WHERE employee.uid=staff.uid)
    AND NOT EXISTS (
      SELECT 1 FROM eb_system_store_staff peer
      WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.uid=staff.uid
    )
  )
  AND staff.account<>''
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_store_staff peer
    WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.account=staff.account
  );

INSERT INTO tmp_sier_candidate
  (staff_id,previous_employee_id,source_type,source_hash,employee_name,employee_phone,employee_uid,default_internal)
SELECT staff.id, staff.employee_id, 'staff_phone', SHA2(CONCAT('staff_phone:',staff.phone),256),
  IF(TRIM(staff.staff_name)='',CONCAT('历史任职#',staff.id),LEFT(TRIM(staff.staff_name),64)),
  staff.phone, NULL,
  IF(COALESCE(staff.is_hezuofang,0)=0 AND COALESCE(staff.is_fencheng,0)=0,1,0)
FROM eb_system_store_staff staff
LEFT JOIN eb_employee current_employee ON current_employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR current_employee.id IS NULL
       OR current_employee.status<>1 OR current_employee.is_del<>0)
  AND NOT (
    staff.uid>0
    AND NOT EXISTS (SELECT 1 FROM eb_employee employee WHERE employee.uid=staff.uid)
    AND NOT EXISTS (
      SELECT 1 FROM eb_system_store_staff peer
      WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.uid=staff.uid
    )
  )
  AND NOT (
    staff.account<>''
    AND NOT EXISTS (
      SELECT 1 FROM eb_system_store_staff peer
      WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.account=staff.account
    )
  )
  AND staff.phone REGEXP '^1[3-9][0-9]{9}$'
  AND NOT EXISTS (SELECT 1 FROM eb_employee employee WHERE employee.phone=staff.phone)
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_store_staff peer
    WHERE peer.status=1 AND peer.is_del=0 AND peer.id<>staff.id AND peer.phone=staff.phone
  );

SELECT COUNT(*) INTO @sier_candidate_count FROM tmp_sier_candidate;
SELECT COUNT(*) INTO @sier_invalid_count
FROM eb_system_store_staff staff
LEFT JOIN eb_employee employee ON employee.id=staff.employee_id
WHERE staff.status=1 AND staff.is_del=0
  AND (staff.employee_id IS NULL OR staff.employee_id=0 OR employee.id IS NULL
       OR employee.status<>1 OR employee.is_del<>0);
SET @sier_guard_sql := IF(@sier_candidate_count=@sier_invalid_count,
  'SELECT ''CANDIDATE_GUARD_OK'' AS candidate_guard_result',
  'SELECT * FROM STOP_ACTIVE_STORE_STAFF_IDENTITY_CANDIDATE_DRIFT');
PREPARE sier_guard_stmt FROM @sier_guard_sql;
EXECUTE sier_guard_stmt;
DEALLOCATE PREPARE sier_guard_stmt;

DROP PROCEDURE IF EXISTS sier_apply_identity_repair;
DELIMITER $$
CREATE PROCEDURE sier_apply_identity_repair()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_staff_id INT UNSIGNED;
  DECLARE v_previous_employee_id INT UNSIGNED;
  DECLARE v_source_type VARCHAR(32);
  DECLARE v_source_hash CHAR(64);
  DECLARE v_employee_name VARCHAR(64);
  DECLARE v_employee_phone CHAR(11);
  DECLARE v_employee_uid INT UNSIGNED;
  DECLARE v_default_internal TINYINT;
  DECLARE v_employee_id INT UNSIGNED;
  DECLARE candidate_cursor CURSOR FOR
    SELECT staff_id,previous_employee_id,source_type,source_hash,employee_name,employee_phone,employee_uid,default_internal
    FROM tmp_sier_candidate ORDER BY staff_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done=1;

  OPEN candidate_cursor;
  repair_loop: LOOP
    FETCH candidate_cursor INTO v_staff_id,v_previous_employee_id,v_source_type,v_source_hash,
      v_employee_name,v_employee_phone,v_employee_uid,v_default_internal;
    IF done=1 THEN LEAVE repair_loop; END IF;

    INSERT INTO eb_employee
      (name,phone,uid,status,employment_type_code,employment_type_version,auth_version,is_del,add_time,update_time)
    VALUES
      (v_employee_name,v_employee_phone,v_employee_uid,1,
       IF(v_default_internal=1,'internal',NULL),IF(v_default_internal=1,1,0),1,0,@sier_now,@sier_now);
    SET v_employee_id=LAST_INSERT_ID();

    UPDATE eb_system_store_staff
    SET employee_id=v_employee_id
    WHERE id=v_staff_id AND status=1 AND is_del=0
      AND (employee_id IS NULL OR employee_id=0 OR NOT EXISTS (
        SELECT 1 FROM eb_employee current_employee
        WHERE current_employee.id=eb_system_store_staff.employee_id
          AND current_employee.status=1 AND current_employee.is_del=0
      ));
    IF ROW_COUNT()<>1 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='STOP_ACTIVE_STORE_STAFF_IDENTITY_CONCURRENT_CHANGE';
    END IF;

    INSERT INTO eb_employee_change_log
      (employee_id,action,target_type,target_id,source,before_data,after_data,reason,
       operator_type,operator_id,operator_name,operator_ip,request_id,add_time)
    VALUES
      (v_employee_id,'employee_identity_repaired_from_staff','store_staff',v_staff_id,'migration',
       CONCAT('{"staffId":',v_staff_id,',"employeeId":',IFNULL(v_previous_employee_id,'null'),
         ',"sourceType":"',v_source_type,'","sourceHash":"',v_source_hash,'"}'),
       CONCAT('{"staffId":',v_staff_id,',"employeeId":',v_employee_id,
         ',"employmentType":"',IF(v_default_internal=1,'internal','unclassified'),'"}'),
       '按唯一旧任职身份补齐统一员工主档','system',0,'database_upgrade','',@sier_key,@sier_now);
  END LOOP;
  CLOSE candidate_cursor;
END$$
DELIMITER ;

START TRANSACTION;
CALL sier_apply_identity_repair();
COMMIT;
DROP PROCEDURE IF EXISTS sier_apply_identity_repair;

SELECT COUNT(*) AS repaired_assignment_count FROM tmp_sier_candidate;
DROP TEMPORARY TABLE IF EXISTS tmp_sier_candidate;
SELECT 'APPLY_OK' AS apply_result;
