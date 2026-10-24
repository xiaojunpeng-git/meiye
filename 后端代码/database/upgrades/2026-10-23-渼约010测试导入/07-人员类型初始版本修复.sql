-- 010 导入修复：已有 internal 分类必须配套正数版本，不能绕过详情读取的严格校验。
-- 只修复已核实的 222 个员工的初始版本；不改姓名、手机号、分类、权限、任职或历史交易。
USE mohe_010_app;
SET SESSION group_concat_max_len=100000;
CREATE TABLE IF NOT EXISTS codex_010_staff_type_version_repair (
 employee_id INT UNSIGNED NOT NULL PRIMARY KEY,
 old_type VARCHAR(16) NOT NULL,
 old_version BIGINT UNSIGNED NOT NULL,
 new_version BIGINT UNSIGNED NOT NULL,
 repaired_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP PROCEDURE IF EXISTS codex_010_repair_staff_type_version;
DELIMITER //
CREATE PROCEDURE codex_010_repair_staff_type_version()
BEGIN
 DECLARE n INT DEFAULT 0;
 DECLARE fingerprint CHAR(64);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION
 BEGIN
  ROLLBACK;
  RESIGNAL;
 END;
 START TRANSACTION;
 -- 锁定目标员工；候选 ID 集变化或已执行过则拒绝，不把后续手工编辑纳入修复。
 SELECT COUNT(*),SHA2(GROUP_CONCAT(id ORDER BY id SEPARATOR ','),256) INTO n,fingerprint
 FROM eb_employee WHERE employment_type_code='internal' AND employment_type_version=0 FOR UPDATE;
 IF n<>222 OR fingerprint<>'c4f1efa4303e1bbc77a8b336569cbeee5bb346c327fb91bf7b6b7a5cd30b7125'
    OR (SELECT COUNT(*) FROM codex_010_staff_type_version_repair)<>0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 staff type repair baseline mismatch or already applied';
 END IF;
 INSERT INTO codex_010_staff_type_version_repair
 SELECT id,employment_type_code,employment_type_version,1,NOW()
 FROM eb_employee WHERE employment_type_code='internal' AND employment_type_version=0;
 UPDATE eb_employee e JOIN codex_010_staff_type_version_repair a ON a.employee_id=e.id
 SET e.employment_type_version=a.new_version
 WHERE e.employment_type_code=a.old_type AND e.employment_type_version=a.old_version;
 IF ROW_COUNT()<>222 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='010 staff type repair changed row mismatch';
 END IF;
 COMMIT;
END//
DELIMITER ;
CALL codex_010_repair_staff_type_version();
DROP PROCEDURE codex_010_repair_staff_type_version;
