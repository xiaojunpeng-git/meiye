-- 部分创表恢复（可直接执行）｜C1-00001-00018
-- 与完整回滚分开：本脚本只处理「部分创表」场景。
--
-- 行为（MySQL 5.6 兼容，无 JSON／CTE／窗口函数）：
-- 1. 升级键已登记 -> 禁止部分恢复；
-- 2. 三张目标表逐列、逐索引、逐表选项核对 02-正式升级.sql 的结构；
-- 3. 任一异构同名表或任一已存表非空 -> 整体停止，不 DROP 任何表；
-- 4. 仅 DROP：存在、为空、且与 02 精确结构一致的本升级表。
--
-- 调用方应以目标数据库作为 mysql 的默认库：
--   mysql <database> < 05-部分创表恢复.sql
-- Shell 入口使用同一结构合同：
--   MYSQL_CONTAINER=... bash 05-部分创表恢复.sh <database>

SET SESSION group_concat_max_len = 65535;
SET @db := DATABASE();
SET @upgrade_key := '20260727-001-cashier-v3-command-idem';
SET @registered := 0;
SET @reg_cnt := 0;
SET @hetero := 0;
SET @nonempty := 0;
SET @blocked := 0;

DROP TEMPORARY TABLE IF EXISTS _c1a_partial_targets;
CREATE TEMPORARY TABLE _c1a_partial_targets (
  tbl VARCHAR(64) NOT NULL PRIMARY KEY,
  expected_cols VARCHAR(4096) NOT NULL,
  expected_shape VARCHAR(128) NOT NULL,
  expected_idx VARCHAR(2048) NOT NULL,
  exists_flag TINYINT NOT NULL DEFAULT 0,
  actual_cols VARCHAR(4096) NULL,
  actual_shape VARCHAR(128) NULL,
  actual_idx VARCHAR(2048) NULL,
  structure_ok TINYINT NOT NULL DEFAULT 0,
  row_cnt BIGINT UNSIGNED NOT NULL DEFAULT 0
);

INSERT INTO _c1a_partial_targets (tbl, expected_cols, expected_shape, expected_idx) VALUES
('eb_cashier_v3_command_receipt',
 'id|bigint(20) unsigned|NO|<NULL>|auto_increment||;idempotency_key|varchar(128)|NO|||ascii|ascii_bin;action|varchar(64)|NO|||ascii|ascii_bin;store_id|int(11) unsigned|NO|0|||;operator_id|int(11) unsigned|NO|0|||;state_context_id|varchar(64)|NO|||ascii|ascii_bin;request_hash|char(64)|NO|||ascii|ascii_bin;contexts_hash|char(64)|NO|||ascii|ascii_bin;contexts_json|mediumtext|YES|<NULL>||utf8mb4|utf8mb4_general_ci;status|tinyint(4)|NO|0|||;result_code|varchar(64)|NO|||ascii|ascii_bin;result_message|varchar(255)|NO|||utf8mb4|utf8mb4_general_ci;result_json|mediumtext|YES|<NULL>||utf8mb4|utf8mb4_general_ci;business_no|varchar(64)|NO|||ascii|ascii_bin;operator_ip|varchar(64)|NO|||ascii|ascii_bin;add_time|int(11) unsigned|NO|0|||;finish_time|int(11) unsigned|NO|0|||',
 'InnoDB|utf8mb4_general_ci',
 'PRIMARY|0|BTREE|id|;idx_archive_time|1|BTREE|add_time|;idx_business_no|1|BTREE|business_no|;idx_state_context|1|BTREE|state_context_id,add_time|,;idx_store_action_time|1|BTREE|store_id,action,add_time|,,;uk_idempotency_key|0|BTREE|idempotency_key|'),
('eb_cashier_v3_resource_version',
 'id|bigint(20) unsigned|NO|<NULL>|auto_increment||;scope_type|varchar(16)|NO|||ascii|ascii_bin;scope_id|varchar(32)|NO|||ascii|ascii_bin;resource_kind|varchar(32)|NO|||ascii|ascii_bin;resource_id|varchar(64)|NO|||ascii|ascii_bin;current_version|bigint(20) unsigned|NO|1|||;last_action|varchar(64)|NO|||ascii|ascii_bin;add_time|int(11) unsigned|NO|0|||;update_time|int(11) unsigned|NO|0|||',
 'InnoDB|utf8mb4_general_ci',
 'PRIMARY|0|BTREE|id|;idx_scope_kind|1|BTREE|scope_type,scope_id,resource_kind|,,;idx_update_time|1|BTREE|update_time|;uk_scope_resource|0|BTREE|scope_type,scope_id,resource_kind,resource_id|,,,'),
('eb_cashier_v3_state_context',
 'id|bigint(20) unsigned|NO|<NULL>|auto_increment||;state_context_id|varchar(64)|NO|||ascii|ascii_bin;store_id|int(11) unsigned|NO|0|||;operator_id|int(11) unsigned|NO|0|||;client_session_id|varchar(128)|NO|||ascii|ascii_bin;current_revision|bigint(20) unsigned|NO|0|||;add_time|int(11) unsigned|NO|0|||;last_seen_time|int(11) unsigned|NO|0|||',
 'InnoDB|utf8mb4_general_ci',
 'PRIMARY|0|BTREE|id|;idx_last_seen|1|BTREE|last_seen_time|;uk_identity|0|BTREE|store_id,operator_id,client_session_id|,,;uk_state_context_id|0|BTREE|state_context_id|');

-- 升级键已登记时先记录阻断原因；后续 DROP 条件也包含 @blocked，
-- 即使调用方使用 mysql --force 继续读脚本，也不会删除任何表。
SELECT COUNT(*) INTO @registered
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'eb_database_upgrade_log';
SET @sql := IF(
  @registered > 0,
  CONCAT('SELECT COUNT(*) INTO @reg_cnt FROM `', @db, '`.`eb_database_upgrade_log` WHERE upgrade_key = ''', @upgrade_key, ''''),
  'SELECT 0 INTO @reg_cnt'
);
PREPARE s_reg FROM @sql; EXECUTE s_reg; DEALLOCATE PREPARE s_reg;

UPDATE _c1a_partial_targets t
LEFT JOIN information_schema.TABLES it
  ON it.TABLE_SCHEMA = @db AND it.TABLE_NAME = t.tbl
SET t.exists_flag = IF(it.TABLE_NAME IS NULL, 0, 1),
    t.actual_shape = IF(it.TABLE_NAME IS NULL, NULL, CONCAT(IFNULL(it.ENGINE,''),'|',IFNULL(it.TABLE_COLLATION,'')));

DROP TEMPORARY TABLE IF EXISTS _c1a_actual_columns;
CREATE TEMPORARY TABLE _c1a_actual_columns AS
SELECT TABLE_NAME AS tbl,
       GROUP_CONCAT(
         CONCAT(COLUMN_NAME,'|',COLUMN_TYPE,'|',IS_NULLABLE,'|',
           IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),'|',IFNULL(EXTRA,''),'|',
           IFNULL(CHARACTER_SET_NAME,''),'|',IFNULL(COLLATION_NAME,''))
         ORDER BY ORDINAL_POSITION SEPARATOR ';'
       ) AS actual_cols
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context')
GROUP BY TABLE_NAME;
UPDATE _c1a_partial_targets t
JOIN _c1a_actual_columns c ON c.tbl = t.tbl
SET t.actual_cols = c.actual_cols;

DROP TEMPORARY TABLE IF EXISTS _c1a_actual_indexes;
CREATE TEMPORARY TABLE _c1a_actual_indexes AS
SELECT TABLE_NAME AS tbl,
       GROUP_CONCAT(CONCAT(INDEX_NAME,'|',NON_UNIQUE,'|',INDEX_TYPE,'|',cols,'|',parts)
                    ORDER BY (INDEX_NAME='PRIMARY') DESC, INDEX_NAME SEPARATOR ';') AS actual_idx
FROM (
  SELECT TABLE_NAME, INDEX_NAME,
         MAX(NON_UNIQUE) AS NON_UNIQUE,
         MAX(INDEX_TYPE) AS INDEX_TYPE,
         GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols,
         GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context')
  GROUP BY TABLE_NAME, INDEX_NAME
) AS ix
GROUP BY TABLE_NAME;
UPDATE _c1a_partial_targets t
JOIN _c1a_actual_indexes i ON i.tbl = t.tbl
SET t.actual_idx = i.actual_idx;

UPDATE _c1a_partial_targets
SET structure_ok = IF(
  exists_flag = 1
  AND actual_cols = expected_cols
  AND actual_shape = expected_shape
  AND actual_idx = expected_idx,
  1, 0
);

SELECT CONCAT('TABLE_HETEROGENEOUS ', tbl) AS notice
FROM _c1a_partial_targets
WHERE exists_flag = 1 AND structure_ok = 0;
SELECT COUNT(*) INTO @hetero
FROM _c1a_partial_targets
WHERE exists_flag = 1 AND structure_ok = 0;

-- 行数必须用真实 COUNT(*)，不能使用 InnoDB 的近似 TABLE_ROWS。
SET @cnt_receipt := 0;
SET @cnt_version := 0;
SET @cnt_context := 0;
SET @sql := IF(
  (SELECT exists_flag FROM _c1a_partial_targets WHERE tbl='eb_cashier_v3_command_receipt') = 1,
  'SELECT COUNT(*) INTO @cnt_receipt FROM `eb_cashier_v3_command_receipt`',
  'SELECT 0 INTO @cnt_receipt'
);
PREPARE s1 FROM @sql; EXECUTE s1; DEALLOCATE PREPARE s1;
SET @sql := IF(
  (SELECT exists_flag FROM _c1a_partial_targets WHERE tbl='eb_cashier_v3_resource_version') = 1,
  'SELECT COUNT(*) INTO @cnt_version FROM `eb_cashier_v3_resource_version`',
  'SELECT 0 INTO @cnt_version'
);
PREPARE s2 FROM @sql; EXECUTE s2; DEALLOCATE PREPARE s2;
SET @sql := IF(
  (SELECT exists_flag FROM _c1a_partial_targets WHERE tbl='eb_cashier_v3_state_context') = 1,
  'SELECT COUNT(*) INTO @cnt_context FROM `eb_cashier_v3_state_context`',
  'SELECT 0 INTO @cnt_context'
);
PREPARE s3 FROM @sql; EXECUTE s3; DEALLOCATE PREPARE s3;
UPDATE _c1a_partial_targets SET row_cnt = @cnt_receipt WHERE tbl='eb_cashier_v3_command_receipt';
UPDATE _c1a_partial_targets SET row_cnt = @cnt_version WHERE tbl='eb_cashier_v3_resource_version';
UPDATE _c1a_partial_targets SET row_cnt = @cnt_context WHERE tbl='eb_cashier_v3_state_context';

SELECT CONCAT('TABLE_EXISTS ',tbl,' rows=',row_cnt) AS notice
FROM _c1a_partial_targets WHERE exists_flag = 1;
SELECT COUNT(*) INTO @nonempty
FROM _c1a_partial_targets WHERE exists_flag = 1 AND row_cnt > 0;
SET @blocked := IF(@reg_cnt > 0 OR @hetero > 0 OR @nonempty > 0, 1, 0);

SELECT IF(@reg_cnt > 0, 'STOP_PARTIAL_DDL_UPGRADE_REGISTERED', NULL) AS notice;
SELECT IF(@hetero > 0, 'STOP_PARTIAL_DDL_HETEROGENEOUS', NULL) AS notice;
SELECT IF(@nonempty > 0, 'STOP_PARTIAL_DDL_NONEMPTY', NULL) AS notice;

-- 所有 DROP 都显式受 @blocked 和 structure_ok 保护，避免 --force 继续时误删。
SET @sql := IF(
  @blocked = 0 AND (SELECT exists_flag=1 AND row_cnt=0 AND structure_ok=1 FROM _c1a_partial_targets WHERE tbl='eb_cashier_v3_command_receipt') = 1,
  'DROP TABLE `eb_cashier_v3_command_receipt`',
  'SELECT ''SKIP_DROP_RECEIPT'' AS notice'
);
PREPARE d1 FROM @sql; EXECUTE d1; DEALLOCATE PREPARE d1;
SET @sql := IF(
  @blocked = 0 AND (SELECT exists_flag=1 AND row_cnt=0 AND structure_ok=1 FROM _c1a_partial_targets WHERE tbl='eb_cashier_v3_resource_version') = 1,
  'DROP TABLE `eb_cashier_v3_resource_version`',
  'SELECT ''SKIP_DROP_VERSION'' AS notice'
);
PREPARE d2 FROM @sql; EXECUTE d2; DEALLOCATE PREPARE d2;
SET @sql := IF(
  @blocked = 0 AND (SELECT exists_flag=1 AND row_cnt=0 AND structure_ok=1 FROM _c1a_partial_targets WHERE tbl='eb_cashier_v3_state_context') = 1,
  'DROP TABLE `eb_cashier_v3_state_context`',
  'SELECT ''SKIP_DROP_CONTEXT'' AS notice'
);
PREPARE d3 FROM @sql; EXECUTE d3; DEALLOCATE PREPARE d3;

-- 普通 mysql 客户端会因不存在的阻断表返回非零；即使使用 --force，前面的
-- @blocked 条件也保证没有 DROP，且输出仍含可审计的阻断原因。
SET @abort_table := IF(
  @reg_cnt > 0, 'STOP_PARTIAL_DDL_UPGRADE_REGISTERED',
  IF(@hetero > 0, 'STOP_PARTIAL_DDL_HETEROGENEOUS',
    IF(@nonempty > 0, 'STOP_PARTIAL_DDL_NONEMPTY', ''))
);
SET @sql := IF(
  @blocked > 0,
  CONCAT('SELECT * FROM `', @abort_table, '`'),
  'SELECT ''PARTIAL_DDL_RECOVERY_OK'' AS recovery_result'
);
PREPARE s_final FROM @sql; EXECUTE s_final; DEALLOCATE PREPARE s_final;
