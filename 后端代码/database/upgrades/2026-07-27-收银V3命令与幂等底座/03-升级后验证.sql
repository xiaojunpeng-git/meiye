-- upgrade_key: 20260727-001-cashier-v3-command-idem
-- 升级后完整结构验证（与 02-正式升级.sql 双向 metadata 合同一致）。
-- 可重复执行；有业务数据时仍只按结构判定，不要求行数为 0。
SET NAMES utf8mb4;
SET @db := DATABASE();
SET @failures := 0;

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
    'eb_cashier_v3_command_receipt',
    'eb_cashier_v3_resource_version',
    'eb_cashier_v3_state_context'
  )
GROUP BY s.TABLE_NAME, s.INDEX_NAME;

-- 1) 三表存在 + InnoDB + utf8mb4
SELECT COUNT(*) INTO @table_ok
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@db
  AND TABLE_NAME IN ('eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context')
  AND ENGINE='InnoDB' AND TABLE_COLLATION = 'utf8mb4_general_ci';
SET @failures := @failures + IF(@table_ok = 3, 0, 1);

-- 2) 双向列合同
SELECT COUNT(*) INTO @col_miss
FROM _c1a_exp_col e
LEFT JOIN _c1a_act_col a ON a.tbl=e.tbl AND a.col=e.col
WHERE a.col IS NULL;
SELECT COUNT(*) INTO @col_extra
FROM _c1a_act_col a
LEFT JOIN _c1a_exp_col e ON e.tbl=a.tbl AND e.col=a.col
WHERE e.col IS NULL;
SELECT COUNT(*) INTO @col_bad
FROM _c1a_exp_col e
INNER JOIN _c1a_act_col a ON a.tbl=e.tbl AND a.col=e.col
WHERE (
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
SET @failures := @failures + @col_miss + @col_extra + @col_bad;

-- 3) 双向索引合同（含 NON_UNIQUE、列序、SUB_PART）
SELECT COUNT(*) INTO @idx_miss
FROM _c1a_exp_idx e
LEFT JOIN _c1a_act_idx a ON a.tbl=e.tbl AND a.idx_cols=e.idx_cols AND a.non_unique=e.non_unique
  AND IFNULL(a.sub_part,'') = IFNULL(e.sub_part,'')
WHERE a.idx_cols IS NULL;
SELECT COUNT(*) INTO @idx_extra
FROM _c1a_act_idx a
LEFT JOIN _c1a_exp_idx e ON e.tbl=a.tbl AND e.idx_cols=a.idx_cols AND e.non_unique=a.non_unique
  AND IFNULL(e.sub_part,'') = IFNULL(a.sub_part,'')
WHERE e.idx_cols IS NULL;
SET @failures := @failures + @idx_miss + @idx_extra;

-- 4) 无生成列
SELECT COUNT(*) INTO @gen FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME IN ('eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context')
  AND EXTRA LIKE '%GENERATED%';
SET @failures := @failures + IF(@gen=0,0,1);

SELECT @failures AS verify_failure_count,
       @col_miss AS verify_col_miss,
       @col_extra AS verify_col_extra,
       @col_bad AS verify_col_bad,
       @idx_miss AS verify_idx_miss,
       @idx_extra AS verify_idx_extra;

SET @sql := IF(@failures = 0, 'SELECT ''VERIFY_OK'' AS verify_result', 'SELECT * FROM STOP_VERIFY_FAILED');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
