-- upgrade_key: 20260729-005-employee-employment-type-authority
-- MySQL 5.6.51 compatible and replay-safe after an audited partial DDL.
SET NAMES utf8mb4;
SET @eta_db := DATABASE();

SELECT COUNT(*) INTO @eta_code_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@eta_db AND TABLE_NAME='eb_employee'
  AND COLUMN_NAME='employment_type_code';
SET @eta_add_code_sql := IF(
  @eta_code_exists=0,
  'ALTER TABLE eb_employee ADD COLUMN employment_type_code varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT ''人员类型 internal/partner/outsourced'' AFTER status',
  'SELECT ''employment_type_code exists'' AS ddl_replay'
);
PREPARE eta_add_code_stmt FROM @eta_add_code_sql;
EXECUTE eta_add_code_stmt;
DEALLOCATE PREPARE eta_add_code_stmt;

SELECT COUNT(*) INTO @eta_version_exists
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@eta_db AND TABLE_NAME='eb_employee'
  AND COLUMN_NAME='employment_type_version';
SET @eta_add_version_sql := IF(
  @eta_version_exists=0,
  'ALTER TABLE eb_employee ADD COLUMN employment_type_version bigint(20) unsigned NOT NULL DEFAULT 0 COMMENT ''人员类型乐观锁版本，未分类为0'' AFTER employment_type_code',
  'SELECT ''employment_type_version exists'' AS ddl_replay'
);
PREPARE eta_add_version_stmt FROM @eta_add_version_sql;
EXECUTE eta_add_version_stmt;
DEALLOCATE PREPARE eta_add_version_stmt;

-- 历史员工一律保持 NULL / 0 未分类。旧任职分成和合作方标记不足以
-- 区分 partner 与 outsourced，且本次改造不回填或改写旧员工档案。

INSERT INTO eb_system_menus
  (pid,type,icon,menu_name,module,controller,action,api_url,methods,params,
   sort,is_show,is_show_path,access,menu_path,path,auth_type,header,is_header,unique_auth,is_del)
SELECT
  parent.id,1,'','人员类型管理','admin','','','','','[]',
  0,0,0,1,'',CAST(parent.id AS CHAR),2,'',0,'setting-staff-employment-type',0
FROM eb_system_menus parent
WHERE parent.unique_auth='setting-staff-index'
  AND parent.is_del=0
  AND parent.type=1
  AND NOT EXISTS (
    SELECT 1 FROM eb_system_menus existing
    WHERE existing.unique_auth='setting-staff-employment-type' AND existing.is_del=0
  );

SELECT
  employee.id AS employee_id,
  employee.name AS employee_name,
  employee.phone AS employee_phone,
  GROUP_CONCAT(DISTINCT staff.store_id ORDER BY staff.store_id) AS active_store_ids,
  MAX(staff.is_fencheng) AS has_legacy_share_flag,
  MAX(staff.is_hezuofang) AS has_legacy_partner_flag,
  '人工选择 partner 或 outsourced' AS required_action
FROM eb_employee employee
INNER JOIN eb_system_store_staff staff
  ON staff.employee_id=employee.id
  AND staff.is_del=0
  AND staff.status=1
  AND (staff.is_fencheng=1 OR staff.is_hezuofang=1)
WHERE employee.employment_type_code IS NULL
  AND employee.employment_type_version=0
GROUP BY employee.id,employee.name,employee.phone
ORDER BY employee.id;

SELECT 'APPLY_OK' AS apply_result;
