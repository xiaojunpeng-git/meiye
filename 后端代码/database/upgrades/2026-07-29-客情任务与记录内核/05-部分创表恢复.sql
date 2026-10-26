-- upgrade_key: 20260729-001-customer-care-core
-- 部分创表恢复只做无损校验，不删除或改写任何客情目标表。
-- 仅 1 至 2 张由 02 精确创建且仍为空的残留表可以进入 02 -> 03 补齐流程。
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @care_db := DATABASE();
SET @care_upgrade_key := '20260729-001-customer-care-core';
SET @care_registered := 0;
SET @care_c1_registered := 0;
SET @care_employee_registered := 0;
SET @care_log_exists := 0;
SET @care_log_key_column := 0;
SET @care_log_valid := 0;
SET @care_existing_count := 0;
SET @care_heterogeneous := 0;
SET @care_nonempty := 0;

DROP TEMPORARY TABLE IF EXISTS `_care_partial_targets`;
CREATE TEMPORARY TABLE `_care_partial_targets` (
  `table_name` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expected_column_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expected_index_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expected_shape` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `exists_flag` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `actual_column_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `actual_index_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `actual_shape` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `structure_ok` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `row_count` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`table_name`)
) ENGINE=MEMORY DEFAULT CHARSET=ascii COLLATE=ascii_bin;

-- 哈希由 tests/customer-care/sql-matrix.sh 在真实 MySQL 5.6.51 上从 02 新装结构复核。
INSERT INTO `_care_partial_targets`
  (`table_name`,`expected_column_hash`,`expected_index_hash`,`expected_shape`)
VALUES
  ('eb_customer_care_task',
   '4c93b5b379d01462c23e55694aa73bf18e25969d07ba7346d02b377dd5c91f2a',
   'd5610705dd52f129d7f2445314c9e55b51ac9906c269219c1f3940ac60d6e0f2',
   'InnoDB|utf8mb4_general_ci'),
  ('eb_customer_care_record',
   '51f6254953d53384601d2419b6bc6143e250b7265275163a1a6d42ff9f9feb45',
   '5326e2df1d07bf515c60eff99ad54b70810d3d731a53bc86f1139261c6ccccb4',
   'InnoDB|utf8mb4_general_ci'),
  ('eb_customer_care_operation',
   '46aa9c9306fea04818526d9125fbf8649f70af683239f110b084f87167d1d9b3',
   'b97194d0b7d27f9c6ae4def620e2bfc8aa1dd2f324dd75091c73252e797b92fa',
   'InnoDB|utf8mb4_general_ci');

SELECT COUNT(*) INTO @care_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_database_upgrade_log';
SELECT COUNT(*) INTO @care_log_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key'
  AND COLUMN_TYPE='varchar(100)'
  AND IS_NULLABLE='NO';
SET @care_log_valid := IF(@care_log_exists=1 AND @care_log_key_column=1,1,0);
SET @care_registration_sql := IF(
  @care_log_valid=1,
  CONCAT(
    'SELECT COALESCE(SUM(upgrade_key=''',
    @care_upgrade_key,
    '''),0),COALESCE(SUM(upgrade_key=''20260727-001-cashier-v3-command-idem''),0),COALESCE(SUM(upgrade_key=''20260719-009-employee-org-leader''),0) INTO @care_registered,@care_c1_registered,@care_employee_registered FROM `',
    REPLACE(@care_db,'`','``'),
    '`.`eb_database_upgrade_log` WHERE `upgrade_key` IN (''',
    @care_upgrade_key,
    ''',''20260727-001-cashier-v3-command-idem'',''20260719-009-employee-org-leader'')'
  ),
  'SET @care_registered:=0,@care_c1_registered:=0,@care_employee_registered:=0'
);
PREPARE care_registration_stmt FROM @care_registration_sql;
EXECUTE care_registration_stmt;
DEALLOCATE PREPARE care_registration_stmt;

SELECT COUNT(*) INTO @care_c1_receipt_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME='eb_cashier_v3_command_receipt'
  AND ENGINE='InnoDB'
  AND TABLE_COLLATION='utf8mb4_general_ci';
SELECT COUNT(*) INTO @care_c1_receipt_column_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_cashier_v3_command_receipt';
SELECT COUNT(*) INTO @care_c1_receipt_unique_contract_count
FROM (
  SELECT INDEX_NAME,MAX(NON_UNIQUE) AS non_unique,
         GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@care_db AND TABLE_NAME='eb_cashier_v3_command_receipt'
  GROUP BY INDEX_NAME
  HAVING non_unique=0 AND index_columns='idempotency_key'
) care_c1_receipt_unique;
SELECT COUNT(*) INTO @care_employee_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME IN ('eb_employee','eb_system_store_staff')
  AND ENGINE='InnoDB';
SELECT COUNT(*) INTO @care_employee_column_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND COLUMN_NAME IN ('id','status','is_del'))
    OR (TABLE_NAME='eb_system_store_staff' AND COLUMN_NAME IN (
      'id','store_id','employee_id','staff_name','status','is_del'
    ))
  );
SELECT COUNT(*) INTO @care_employee_id_column_contract_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND COLUMN_NAME='id'
      AND COLUMN_TYPE='int(10) unsigned' AND IS_NULLABLE='NO')
    OR (TABLE_NAME='eb_system_store_staff' AND COLUMN_NAME='employee_id'
      AND COLUMN_TYPE='int(10) unsigned' AND IS_NULLABLE='YES')
  );
SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,'|',INDEX_NAME))
INTO @care_employee_index_contract_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@care_db
  AND (
    (TABLE_NAME='eb_employee' AND INDEX_NAME='idx_employee_status_del')
    OR (TABLE_NAME='eb_system_store_staff' AND INDEX_NAME IN (
      'idx_staff_employee_store','idx_staff_store_employee'
    ))
  );
SET @care_invalid_active_assignment_count := -1;
SET @care_assignment_sql := IF(
  @care_employee_table_count=2 AND @care_employee_column_contract_count=9,
  'SELECT COUNT(*) INTO @care_invalid_active_assignment_count FROM eb_system_store_staff staff LEFT JOIN eb_employee employee ON employee.id=staff.employee_id WHERE staff.status=1 AND staff.is_del=0 AND (staff.employee_id IS NULL OR staff.employee_id=0 OR employee.id IS NULL OR employee.status<>1 OR employee.is_del<>0)',
  'SET @care_invalid_active_assignment_count:=-1'
);
PREPARE care_assignment_stmt FROM @care_assignment_sql;
EXECUTE care_assignment_stmt;
DEALLOCATE PREPARE care_assignment_stmt;
SET @care_dependency_invalid := IF(
  @care_c1_registered<>1
  OR @care_employee_registered<>1
  OR @care_c1_receipt_table_count<>1
  OR @care_c1_receipt_column_count<>17
  OR @care_c1_receipt_unique_contract_count<>1
  OR @care_employee_table_count<>2
  OR @care_employee_column_contract_count<>9
  OR @care_employee_id_column_contract_count<>2
  OR @care_employee_index_contract_count<>3
  OR @care_invalid_active_assignment_count<>0,
  1,
  0
);

UPDATE `_care_partial_targets` target
LEFT JOIN information_schema.TABLES actual
  ON actual.TABLE_SCHEMA=@care_db AND actual.TABLE_NAME=target.table_name
SET target.exists_flag=IF(actual.TABLE_NAME IS NULL,0,1),
    target.actual_shape=IF(
      actual.TABLE_NAME IS NULL,
      NULL,
      CONCAT(IFNULL(actual.ENGINE,''),'|',IFNULL(actual.TABLE_COLLATION,''))
    );

DROP TEMPORARY TABLE IF EXISTS `_care_actual_columns`;
CREATE TEMPORARY TABLE `_care_actual_columns` AS
SELECT TABLE_NAME AS table_name,
       SHA2(
         GROUP_CONCAT(
           CONCAT(
             COLUMN_NAME,'|',COLUMN_TYPE,'|',IS_NULLABLE,'|',
             IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),'|',
             IFNULL(EXTRA,''),'|',IFNULL(CHARACTER_SET_NAME,''),'|',
             IFNULL(COLLATION_NAME,'')
           )
           ORDER BY ORDINAL_POSITION SEPARATOR ';'
         ),
         256
       ) AS column_hash
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@care_db
  AND TABLE_NAME IN (
    'eb_customer_care_task','eb_customer_care_record','eb_customer_care_operation'
  )
GROUP BY TABLE_NAME;
UPDATE `_care_partial_targets` target
JOIN `_care_actual_columns` actual ON actual.table_name=target.table_name
SET target.actual_column_hash=actual.column_hash;

DROP TEMPORARY TABLE IF EXISTS `_care_actual_indexes`;
CREATE TEMPORARY TABLE `_care_actual_indexes` AS
SELECT table_name,
       SHA2(
         GROUP_CONCAT(
           CONCAT(index_name,'|',non_unique,'|',index_type,'|',index_columns,'|',prefixes)
           ORDER BY (index_name='PRIMARY') DESC,index_name SEPARATOR ';'
         ),
         256
       ) AS index_hash
FROM (
  SELECT TABLE_NAME AS table_name,
         INDEX_NAME AS index_name,
         MAX(NON_UNIQUE) AS non_unique,
         MAX(INDEX_TYPE) AS index_type,
         GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns,
         GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS prefixes
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@care_db
    AND TABLE_NAME IN (
      'eb_customer_care_task','eb_customer_care_record','eb_customer_care_operation'
    )
  GROUP BY TABLE_NAME,INDEX_NAME
) actual_index_parts
GROUP BY table_name;
UPDATE `_care_partial_targets` target
JOIN `_care_actual_indexes` actual ON actual.table_name=target.table_name
SET target.actual_index_hash=actual.index_hash;

UPDATE `_care_partial_targets`
SET structure_ok=IF(
  exists_flag=1
  AND actual_column_hash=expected_column_hash
  AND actual_index_hash=expected_index_hash
  AND actual_shape=expected_shape,
  1,
  0
);

SET @care_count_task := 0;
SET @care_count_record := 0;
SET @care_count_operation := 0;
SET @care_count_sql := IF(
  (SELECT exists_flag FROM `_care_partial_targets`
   WHERE table_name='eb_customer_care_task')=1,
  'SELECT COUNT(*) INTO @care_count_task FROM `eb_customer_care_task`',
  'SELECT 0 INTO @care_count_task'
);
PREPARE care_count_stmt FROM @care_count_sql;
EXECUTE care_count_stmt;
DEALLOCATE PREPARE care_count_stmt;
SET @care_count_sql := IF(
  (SELECT exists_flag FROM `_care_partial_targets`
   WHERE table_name='eb_customer_care_record')=1,
  'SELECT COUNT(*) INTO @care_count_record FROM `eb_customer_care_record`',
  'SELECT 0 INTO @care_count_record'
);
PREPARE care_count_stmt FROM @care_count_sql;
EXECUTE care_count_stmt;
DEALLOCATE PREPARE care_count_stmt;
SET @care_count_sql := IF(
  (SELECT exists_flag FROM `_care_partial_targets`
   WHERE table_name='eb_customer_care_operation')=1,
  'SELECT COUNT(*) INTO @care_count_operation FROM `eb_customer_care_operation`',
  'SELECT 0 INTO @care_count_operation'
);
PREPARE care_count_stmt FROM @care_count_sql;
EXECUTE care_count_stmt;
DEALLOCATE PREPARE care_count_stmt;

UPDATE `_care_partial_targets` SET row_count=@care_count_task
WHERE table_name='eb_customer_care_task';
UPDATE `_care_partial_targets` SET row_count=@care_count_record
WHERE table_name='eb_customer_care_record';
UPDATE `_care_partial_targets` SET row_count=@care_count_operation
WHERE table_name='eb_customer_care_operation';

SELECT SUM(exists_flag) INTO @care_existing_count
FROM `_care_partial_targets`;
SELECT COUNT(*) INTO @care_heterogeneous
FROM `_care_partial_targets`
WHERE exists_flag=1 AND structure_ok=0;
SELECT COUNT(*) INTO @care_nonempty
FROM `_care_partial_targets`
WHERE exists_flag=1 AND row_count>0;

SELECT table_name,exists_flag,structure_ok,row_count,
       actual_column_hash,actual_index_hash,actual_shape
FROM `_care_partial_targets`
ORDER BY table_name;
SELECT IF(@care_log_valid<>1,'STOP_PARTIAL_DDL_UPGRADE_LOG_INVALID',NULL) AS notice;
SELECT IF(@care_registered>0,'STOP_PARTIAL_DDL_UPGRADE_REGISTERED',NULL) AS notice;
SELECT IF(@care_dependency_invalid>0,'STOP_PARTIAL_DDL_DEPENDENCY_INVALID',NULL) AS notice;
SELECT IF(@care_existing_count=0,'STOP_PARTIAL_DDL_ZERO_TABLES',NULL) AS notice;
SELECT IF(@care_existing_count=3,'STOP_PARTIAL_DDL_FULL_INSTALL',NULL) AS notice;
SELECT IF(@care_heterogeneous>0,'STOP_PARTIAL_DDL_HETEROGENEOUS',NULL) AS notice;
SELECT IF(@care_nonempty>0,'STOP_PARTIAL_DDL_NONEMPTY',NULL) AS notice;

SET @care_abort_table := IF(
  @care_log_valid<>1,
  'STOP_PARTIAL_DDL_UPGRADE_LOG_INVALID',
  IF(
    @care_registered>0,
    'STOP_PARTIAL_DDL_UPGRADE_REGISTERED',
    IF(
      @care_dependency_invalid>0,
      'STOP_PARTIAL_DDL_DEPENDENCY_INVALID',
      IF(
        @care_existing_count=0,
        'STOP_PARTIAL_DDL_ZERO_TABLES',
        IF(
          @care_existing_count=3,
          'STOP_PARTIAL_DDL_FULL_INSTALL',
          IF(
            @care_heterogeneous>0,
            'STOP_PARTIAL_DDL_HETEROGENEOUS',
            IF(@care_nonempty>0,'STOP_PARTIAL_DDL_NONEMPTY','')
          )
        )
      )
    )
  )
);
SET @care_finish_sql := IF(
  @care_abort_table='',
  'SELECT ''PARTIAL_DDL_RECOVERY_READY'' AS recovery_result',
  CONCAT('SELECT * FROM `',@care_abort_table,'`')
);
PREPARE care_finish_stmt FROM @care_finish_sql;
EXECUTE care_finish_stmt;
DEALLOCATE PREPARE care_finish_stmt;
