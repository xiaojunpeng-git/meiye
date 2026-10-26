-- upgrade_key: 20260727-001-cashier-v3-command-idem
-- 升级前精确结构校验（双向 metadata 合同）。只读；任一失败主动非零退出。
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @failures := 0;

SELECT @db AS db_name, VERSION() AS mysql_version, @@innodb_large_prefix AS large_prefix;

-- ---------------------------------------------------------------------------
-- 辅助：期望列 / 期望索引（权威来源 0000 登记表 + 02-正式升级.sql）
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS _c1a_exp_col;
CREATE TEMPORARY TABLE _c1a_exp_col (
  tbl VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  col VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  col_type VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  nullable ENUM('YES','NO') NOT NULL,
  col_default VARCHAR(256) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  charset VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  collation VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  extra_need VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  PRIMARY KEY (tbl, col)
) ENGINE=Memory;

DROP TEMPORARY TABLE IF EXISTS _c1a_exp_idx;
CREATE TEMPORARY TABLE _c1a_exp_idx (
  tbl VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  idx_cols VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  non_unique TINYINT NOT NULL,
  sub_part VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  PRIMARY KEY (tbl, idx_cols, non_unique)
) ENGINE=Memory;

-- eb_database_upgrade_log（0000-升级登记表初始化.sql；MySQL 5.6.51 information_schema 实测）
INSERT INTO _c1a_exp_col VALUES
('eb_database_upgrade_log','id','bigint(20) unsigned','NO',NULL,NULL,NULL,'auto_increment'),
('eb_database_upgrade_log','upgrade_key','varchar(100)','NO',NULL,'utf8mb4','utf8mb4_general_ci',''),
('eb_database_upgrade_log','title','varchar(255)','NO','','utf8mb4','utf8mb4_general_ci',''),
('eb_database_upgrade_log','task_file','varchar(500)','NO','','utf8mb4','utf8mb4_general_ci',''),
('eb_database_upgrade_log','sql_checksum','char(64)','NO','','utf8mb4','utf8mb4_general_ci',''),
('eb_database_upgrade_log','code_version','varchar(100)','NO','','utf8mb4','utf8mb4_general_ci',''),
('eb_database_upgrade_log','executed_at','datetime','NO',NULL,NULL,NULL,''),
('eb_database_upgrade_log','executed_by','varchar(100)','NO','','utf8mb4','utf8mb4_general_ci',''),
('eb_database_upgrade_log','result_note','varchar(500)','NO','','utf8mb4','utf8mb4_general_ci',''),
('eb_database_upgrade_log','created_at','timestamp','NO','CURRENT_TIMESTAMP',NULL,NULL,'');

INSERT INTO _c1a_exp_idx VALUES
('eb_database_upgrade_log','id',0,''),
('eb_database_upgrade_log','upgrade_key',0,''),
('eb_database_upgrade_log','executed_at',1,'');

-- eb_cashier_v3_command_receipt
INSERT INTO _c1a_exp_col VALUES
('eb_cashier_v3_command_receipt','id','bigint(20) unsigned','NO',NULL,NULL,NULL,'auto_increment'),
('eb_cashier_v3_command_receipt','idempotency_key','varchar(128)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_command_receipt','action','varchar(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_command_receipt','store_id','int(11) unsigned','NO','0',NULL,NULL,''),
('eb_cashier_v3_command_receipt','operator_id','int(11) unsigned','NO','0',NULL,NULL,''),
('eb_cashier_v3_command_receipt','state_context_id','varchar(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_command_receipt','request_hash','char(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_command_receipt','contexts_hash','char(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_command_receipt','contexts_json','mediumtext','YES',NULL,'utf8mb4','utf8mb4_general_ci',''),
('eb_cashier_v3_command_receipt','status','tinyint(4)','NO','0',NULL,NULL,''),
('eb_cashier_v3_command_receipt','result_code','varchar(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_command_receipt','result_message','varchar(255)','NO','','utf8mb4','utf8mb4_general_ci',''),
('eb_cashier_v3_command_receipt','result_json','mediumtext','YES',NULL,'utf8mb4','utf8mb4_general_ci',''),
('eb_cashier_v3_command_receipt','business_no','varchar(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_command_receipt','operator_ip','varchar(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_command_receipt','add_time','int(11) unsigned','NO','0',NULL,NULL,''),
('eb_cashier_v3_command_receipt','finish_time','int(11) unsigned','NO','0',NULL,NULL,'');

INSERT INTO _c1a_exp_idx VALUES
('eb_cashier_v3_command_receipt','id',0,''),
('eb_cashier_v3_command_receipt','idempotency_key',0,''),
('eb_cashier_v3_command_receipt','store_id,action,add_time',1,''),
('eb_cashier_v3_command_receipt','state_context_id,add_time',1,''),
('eb_cashier_v3_command_receipt','business_no',1,''),
('eb_cashier_v3_command_receipt','add_time',1,'');

-- eb_cashier_v3_resource_version
INSERT INTO _c1a_exp_col VALUES
('eb_cashier_v3_resource_version','id','bigint(20) unsigned','NO',NULL,NULL,NULL,'auto_increment'),
('eb_cashier_v3_resource_version','scope_type','varchar(16)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_resource_version','scope_id','varchar(32)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_resource_version','resource_kind','varchar(32)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_resource_version','resource_id','varchar(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_resource_version','current_version','bigint(20) unsigned','NO','1',NULL,NULL,''),
('eb_cashier_v3_resource_version','last_action','varchar(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_resource_version','add_time','int(11) unsigned','NO','0',NULL,NULL,''),
('eb_cashier_v3_resource_version','update_time','int(11) unsigned','NO','0',NULL,NULL,'');

INSERT INTO _c1a_exp_idx VALUES
('eb_cashier_v3_resource_version','id',0,''),
('eb_cashier_v3_resource_version','scope_type,scope_id,resource_kind,resource_id',0,''),
('eb_cashier_v3_resource_version','scope_type,scope_id,resource_kind',1,''),
('eb_cashier_v3_resource_version','update_time',1,'');

-- eb_cashier_v3_state_context
INSERT INTO _c1a_exp_col VALUES
('eb_cashier_v3_state_context','id','bigint(20) unsigned','NO',NULL,NULL,NULL,'auto_increment'),
('eb_cashier_v3_state_context','state_context_id','varchar(64)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_state_context','store_id','int(11) unsigned','NO','0',NULL,NULL,''),
('eb_cashier_v3_state_context','operator_id','int(11) unsigned','NO','0',NULL,NULL,''),
('eb_cashier_v3_state_context','client_session_id','varchar(128)','NO','','ascii','ascii_bin',''),
('eb_cashier_v3_state_context','current_revision','bigint(20) unsigned','NO','0',NULL,NULL,''),
('eb_cashier_v3_state_context','add_time','int(11) unsigned','NO','0',NULL,NULL,''),
('eb_cashier_v3_state_context','last_seen_time','int(11) unsigned','NO','0',NULL,NULL,'');

INSERT INTO _c1a_exp_idx VALUES
('eb_cashier_v3_state_context','id',0,''),
('eb_cashier_v3_state_context','state_context_id',0,''),
('eb_cashier_v3_state_context','store_id,operator_id,client_session_id',0,''),
('eb_cashier_v3_state_context','last_seen_time',1,'');

-- ---------------------------------------------------------------------------
-- 函数式比较：列默认值归一化
-- ---------------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS _c1a_act_col;
CREATE TEMPORARY TABLE _c1a_act_col AS
SELECT
  CONVERT(c.TABLE_NAME USING utf8mb4) COLLATE utf8mb4_bin AS tbl,
  CONVERT(c.COLUMN_NAME USING utf8mb4) COLLATE utf8mb4_bin AS col,
  CONVERT(c.COLUMN_TYPE USING utf8mb4) COLLATE utf8mb4_bin AS col_type,
  c.IS_NULLABLE AS nullable,
  c.COLUMN_DEFAULT AS col_default,
  c.CHARACTER_SET_NAME AS charset,
  c.COLLATION_NAME AS collation,
  CONVERT(IFNULL(c.EXTRA, '') USING utf8mb4) COLLATE utf8mb4_bin AS extra
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = @db
  AND c.TABLE_NAME IN (
    'eb_database_upgrade_log',
    'eb_cashier_v3_command_receipt',
    'eb_cashier_v3_resource_version',
    'eb_cashier_v3_state_context'
  );

DROP TEMPORARY TABLE IF EXISTS _c1a_act_idx;
CREATE TEMPORARY TABLE _c1a_act_idx AS
SELECT
  CONVERT(s.TABLE_NAME USING utf8mb4) COLLATE utf8mb4_bin AS tbl,
  CONVERT(GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) USING utf8mb4) COLLATE utf8mb4_bin AS idx_cols,
  MAX(s.NON_UNIQUE) AS non_unique,
  -- 无前缀索引时 GROUP_CONCAT(空,空) 会变成「,」；归一为空串后再与合同比较
  CONVERT(IF(SUM(s.SUB_PART IS NOT NULL) = 0, '', GROUP_CONCAT(IFNULL(s.SUB_PART,'') ORDER BY s.SEQ_IN_INDEX)) USING utf8mb4) COLLATE utf8mb4_bin AS sub_part
FROM information_schema.STATISTICS s
WHERE s.TABLE_SCHEMA = @db
  AND s.TABLE_NAME IN (
    'eb_database_upgrade_log',
    'eb_cashier_v3_command_receipt',
    'eb_cashier_v3_resource_version',
    'eb_cashier_v3_state_context'
  )
GROUP BY s.TABLE_NAME, s.INDEX_NAME;

-- ---------------------------------------------------------------------------
-- 1) 升级登记表必须存在且结构符合 0000 合同
-- ---------------------------------------------------------------------------
SELECT COUNT(*) INTO @upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_database_upgrade_log';
SET @failures := @failures + IF(@upgrade_log_exists = 1, 0, 1);
SET @sql := IF(@upgrade_log_exists = 1,
  'SELECT ''UPGRADE_LOG_EXISTS_OK'' AS gate_upgrade_log',
  'SELECT * FROM STOP_UPGRADE_LOG_TABLE_MISSING');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT COUNT(*) INTO @ulog_tbl_bad
FROM information_schema.TABLES t
WHERE t.TABLE_SCHEMA=@db AND t.TABLE_NAME='eb_database_upgrade_log'
  AND (t.ENGINE <> 'InnoDB' OR t.TABLE_COLLATION <> 'utf8mb4_general_ci');
SET @failures := @failures + IF(@upgrade_log_exists = 0 OR @ulog_tbl_bad = 0, 0, 1);
SET @sql := IF(@upgrade_log_exists = 0 OR @ulog_tbl_bad = 0,
  'SELECT ''UPGRADE_LOG_TABLE_META_OK'' AS gate_upgrade_log_meta',
  'SELECT * FROM STOP_UPGRADE_LOG_TABLE_META_MISMATCH');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT COUNT(*) INTO @ulog_col_miss
FROM _c1a_exp_col e
LEFT JOIN _c1a_act_col a ON a.tbl=e.tbl AND a.col=e.col
WHERE e.tbl='eb_database_upgrade_log' AND a.col IS NULL;
SELECT COUNT(*) INTO @ulog_col_extra
FROM _c1a_act_col a
LEFT JOIN _c1a_exp_col e ON e.tbl=a.tbl AND e.col=a.col
WHERE a.tbl='eb_database_upgrade_log' AND e.col IS NULL;
SELECT COUNT(*) INTO @ulog_col_bad
FROM _c1a_exp_col e
INNER JOIN _c1a_act_col a ON a.tbl=e.tbl AND a.col=e.col
WHERE e.tbl='eb_database_upgrade_log'
  AND (
    a.col_type <> e.col_type
    OR a.nullable <> e.nullable
    OR (e.charset IS NOT NULL AND IFNULL(a.charset,'') <> e.charset)
    OR (e.charset IS NULL AND a.charset IS NOT NULL)
    OR (e.collation IS NOT NULL AND IFNULL(a.collation,'') <> e.collation)
    OR (e.collation IS NULL AND a.collation IS NOT NULL)
    OR (e.extra_need='auto_increment' AND a.extra NOT LIKE '%auto_increment%')
    OR (e.col_default IS NULL AND a.col_default IS NOT NULL AND e.col <> 'id')
    OR (e.col_default IS NOT NULL AND e.col_default = 'CURRENT_TIMESTAMP' AND IFNULL(a.col_default,'') NOT IN ('CURRENT_TIMESTAMP'))
    OR (e.col_default IS NOT NULL AND e.col_default <> 'CURRENT_TIMESTAMP' AND IFNULL(a.col_default,'') <> e.col_default)
  );
SELECT COUNT(*) INTO @ulog_idx_miss
FROM _c1a_exp_idx e
LEFT JOIN _c1a_act_idx a ON a.tbl=e.tbl AND a.idx_cols=e.idx_cols AND a.non_unique=e.non_unique
  AND IFNULL(a.sub_part,'') = IFNULL(e.sub_part,'')
WHERE e.tbl='eb_database_upgrade_log' AND a.idx_cols IS NULL;
SELECT COUNT(*) INTO @ulog_idx_extra
FROM _c1a_act_idx a
LEFT JOIN _c1a_exp_idx e ON e.tbl=a.tbl AND e.idx_cols=a.idx_cols AND e.non_unique=a.non_unique
  AND IFNULL(e.sub_part,'') = IFNULL(a.sub_part,'')
WHERE a.tbl='eb_database_upgrade_log' AND e.idx_cols IS NULL;

SET @ulog_struct_bad := @ulog_col_miss + @ulog_col_extra + @ulog_col_bad + @ulog_idx_miss + @ulog_idx_extra;
SET @failures := @failures + IF(@upgrade_log_exists = 0 OR @ulog_struct_bad = 0, 0, 1);
SET @sql := IF(@upgrade_log_exists = 0 OR @ulog_struct_bad = 0,
  'SELECT ''UPGRADE_LOG_STRUCTURE_OK'' AS gate_upgrade_log_structure',
  'SELECT * FROM STOP_UPGRADE_LOG_STRUCTURE_MISMATCH');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 2) 本升级键未执行
-- ---------------------------------------------------------------------------
SET @already := 0;
SELECT COUNT(*) INTO @already FROM `eb_database_upgrade_log` WHERE `upgrade_key`='20260727-001-cashier-v3-command-idem';
SET @failures := @failures + IF(@already = 0, 0, 1);
SET @sql := IF(@already = 0,
  'SELECT ''UPGRADE_KEY_UNUSED_OK'' AS gate_upgrade_key',
  'SELECT * FROM STOP_UPGRADE_KEY_ALREADY_USED');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 3) 目标三表：0 允许；1/2 拒绝；3 须完整兼容
-- ---------------------------------------------------------------------------
SELECT COUNT(*) INTO @existing_tables
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME IN (
    'eb_cashier_v3_command_receipt',
    'eb_cashier_v3_resource_version',
    'eb_cashier_v3_state_context'
  );

SET @failures := @failures + IF(@existing_tables IN (0, 3), 0, 1);
SET @sql := IF(@existing_tables IN (0, 3),
  'SELECT ''TARGET_TABLE_COUNT_OK'' AS gate_table_count',
  'SELECT * FROM STOP_TARGET_TABLE_PARTIAL_EXIST');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 三表全在：双向列／索引合同 + engine/collation + 冲突唯一键（按列组合）
SELECT COUNT(*) INTO @target_tbl_bad
FROM information_schema.TABLES t
WHERE t.TABLE_SCHEMA=@db
  AND t.TABLE_NAME IN (
    'eb_cashier_v3_command_receipt',
    'eb_cashier_v3_resource_version',
    'eb_cashier_v3_state_context'
  )
  AND (t.ENGINE <> 'InnoDB' OR t.TABLE_COLLATION <> 'utf8mb4_general_ci');

SELECT COUNT(*) INTO @target_col_miss
FROM _c1a_exp_col e
LEFT JOIN _c1a_act_col a ON a.tbl=e.tbl AND a.col=e.col
WHERE e.tbl LIKE 'eb_cashier_v3_%' AND a.col IS NULL;

SELECT COUNT(*) INTO @target_col_extra
FROM _c1a_act_col a
LEFT JOIN _c1a_exp_col e ON e.tbl=a.tbl AND e.col=a.col
WHERE a.tbl LIKE 'eb_cashier_v3_%' AND e.col IS NULL;

SELECT COUNT(*) INTO @target_col_bad
FROM _c1a_exp_col e
INNER JOIN _c1a_act_col a ON a.tbl=e.tbl AND a.col=e.col
WHERE e.tbl LIKE 'eb_cashier_v3_%'
  AND (
    a.col_type <> e.col_type
    OR a.nullable <> e.nullable
    OR (e.charset IS NOT NULL AND IFNULL(a.charset,'') <> e.charset)
    OR (e.charset IS NULL AND a.charset IS NOT NULL)
    OR (e.collation IS NOT NULL AND IFNULL(a.collation,'') <> e.collation)
    OR (e.collation IS NULL AND a.collation IS NOT NULL)
    OR (e.extra_need='auto_increment' AND a.extra NOT LIKE '%auto_increment%')
    OR (e.col_default IS NULL AND a.col_default IS NOT NULL AND e.col <> 'id')
    OR (e.col_default IS NOT NULL AND IFNULL(a.col_default,'') <> e.col_default)
  );

SELECT COUNT(*) INTO @target_idx_miss
FROM _c1a_exp_idx e
LEFT JOIN _c1a_act_idx a ON a.tbl=e.tbl AND a.idx_cols=e.idx_cols AND a.non_unique=e.non_unique
  AND IFNULL(a.sub_part,'') = IFNULL(e.sub_part,'')
WHERE e.tbl LIKE 'eb_cashier_v3_%' AND a.idx_cols IS NULL;

SELECT COUNT(*) INTO @target_idx_extra
FROM _c1a_act_idx a
LEFT JOIN _c1a_exp_idx e ON e.tbl=a.tbl AND e.idx_cols=a.idx_cols AND e.non_unique=a.non_unique
  AND IFNULL(e.sub_part,'') = IFNULL(a.sub_part,'')
WHERE a.tbl LIKE 'eb_cashier_v3_%' AND e.idx_cols IS NULL;

-- 冲突唯一键：存在非期望列组合的唯一索引（不限索引名，如 uk_kind_resource）
SELECT COUNT(*) INTO @target_uk_conflict
FROM _c1a_act_idx a
LEFT JOIN _c1a_exp_idx e ON e.tbl=a.tbl AND e.idx_cols=a.idx_cols AND e.non_unique=a.non_unique
  AND IFNULL(e.sub_part,'') = IFNULL(a.sub_part,'')
WHERE a.tbl LIKE 'eb_cashier_v3_%' AND a.non_unique=0 AND e.idx_cols IS NULL;

SET @target_bad := IF(@existing_tables = 3,
  @target_tbl_bad + @target_col_miss + @target_col_extra + @target_col_bad
    + @target_idx_miss + @target_idx_extra + @target_uk_conflict,
  0);
SET @failures := @failures + @target_bad;
SET @sql := IF(@target_bad = 0,
  'SELECT ''TARGET_TABLE_COMPAT_OK'' AS gate_table_compat',
  'SELECT * FROM STOP_TARGET_TABLE_INCOMPATIBLE');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- 4) 表前缀
-- ---------------------------------------------------------------------------
SELECT COUNT(*) INTO @eb_tables FROM information_schema.TABLES WHERE TABLE_SCHEMA=@db AND TABLE_NAME LIKE 'eb\_%';
SET @failures := @failures + IF(@eb_tables > 0, 0, 1);
SET @sql := IF(@eb_tables > 0, 'SELECT ''TABLE_PREFIX_OK'' AS gate_table_prefix', 'SELECT * FROM STOP_TABLE_PREFIX_NOT_EB');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT @failures AS precheck_failure_count;
SET @sql := IF(@failures = 0, 'SELECT ''PRECHECK_OK'' AS precheck_result', 'SELECT * FROM STOP_PRECHECK_FAILED');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
