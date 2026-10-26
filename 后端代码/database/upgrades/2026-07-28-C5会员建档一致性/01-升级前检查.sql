-- upgrade_key: 20260728-002-cashier-v3-member-consistency
-- Read-only precheck. All four member-owned target tables may be absent, or all four must match exactly.
SET NAMES utf8mb4;
SET SESSION group_concat_max_len=1048576;
SET @c5_db := DATABASE();
SET @c5_failures := 0;

SELECT @c5_db AS db_name, VERSION() AS mysql_version;

-- Upgrade log must exist and the key must still be unused.
SELECT COUNT(*) INTO @c5_upgrade_log_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_database_upgrade_log';

SELECT COUNT(*) INTO @c5_upgrade_key_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_database_upgrade_log'
  AND COLUMN_NAME='upgrade_key' AND COLUMN_TYPE='varchar(100)' AND IS_NULLABLE='NO';

SET @c5_failures := @c5_failures
  + IF(@c5_upgrade_log_exists=1,0,1)
  + IF(@c5_upgrade_key_column=1,0,1);

SET @c5_upgrade_key_used := 0;
SET @c5_log_query := IF(
  @c5_upgrade_log_exists=1 AND @c5_upgrade_key_column=1,
  'SELECT COUNT(*) INTO @c5_upgrade_key_used FROM `eb_database_upgrade_log` WHERE `upgrade_key`=''20260728-002-cashier-v3-member-consistency''',
  'SET @c5_upgrade_key_used := 0'
);
PREPARE c5_log_stmt FROM @c5_log_query;
EXECUTE c5_log_stmt;
DEALLOCATE PREPARE c5_log_stmt;
SET @c5_failures := @c5_failures + IF(@c5_upgrade_key_used=0,0,1);

-- C1 command foundation and unified event foundation must both be registered
-- and physically match their exact released metadata contracts.
SET @c5_c1_key_used := 0;
SET @c5_event_key_used := 0;
SET @c5_dependency_log_query := IF(
  @c5_upgrade_log_exists=1 AND @c5_upgrade_key_column=1,
  'SELECT SUM(`upgrade_key`=''20260727-001-cashier-v3-command-idem''), SUM(`upgrade_key`=''20260728-003-cashier-v3-event-outbox'') INTO @c5_c1_key_used,@c5_event_key_used FROM `eb_database_upgrade_log`',
  'SET @c5_c1_key_used:=0,@c5_event_key_used:=0'
);
PREPARE c5_dependency_log_stmt FROM @c5_dependency_log_query;
EXECUTE c5_dependency_log_stmt;
DEALLOCATE PREPARE c5_dependency_log_stmt;
SET @c5_c1_key_used := IFNULL(@c5_c1_key_used,0);
SET @c5_event_key_used := IFNULL(@c5_event_key_used,0);

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @c5_c1_table_count,@c5_c1_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @c5_c1_column_count,@c5_c1_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @c5_c1_index_count,@c5_c1_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
    'eb_cashier_v3_command_receipt','eb_cashier_v3_resource_version','eb_cashier_v3_state_context'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) c5_c1_indexes;

SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,ENGINE,TABLE_COLLATION)
  ORDER BY BINARY TABLE_NAME SEPARATOR '\n'
),256) INTO @c5_event_table_count,@c5_event_table_sha
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
  'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,
    IF(COLUMN_DEFAULT IS NULL,'<NULL>',COLUMN_DEFAULT),
    IFNULL(CHARACTER_SET_NAME,''),IFNULL(COLLATION_NAME,''),IFNULL(EXTRA,''))
  ORDER BY BINARY TABLE_NAME,ORDINAL_POSITION SEPARATOR '\n'
),256) INTO @c5_event_column_count,@c5_event_column_sha
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
  'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
);
SELECT COUNT(*), SHA2(GROUP_CONCAT(
  CONCAT_WS('|',table_name,index_name,non_unique,index_type,index_columns,sub_parts)
  ORDER BY BINARY table_name,BINARY index_name SEPARATOR '\n'
),256) INTO @c5_event_index_count,@c5_event_index_sha
FROM (
  SELECT TABLE_NAME AS table_name,INDEX_NAME AS index_name,
    MAX(NON_UNIQUE) AS non_unique,MAX(INDEX_TYPE) AS index_type,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
    IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
    'eb_cashier_v3_business_event','eb_cashier_v3_outbox',
    'eb_cashier_v3_outbox_attempt','eb_cashier_v3_consumer_once'
  )
  GROUP BY TABLE_NAME,INDEX_NAME
) c5_event_indexes;

SET @c5_dependency_bad :=
    IF(@c5_c1_key_used=1,0,1)
  + IF(@c5_event_key_used=1,0,1)
  + IF(@c5_c1_table_count=3,0,1)
  + IF(@c5_c1_column_count=34,0,1)
  + IF(@c5_c1_index_count=14,0,1)
  + IF(IFNULL(BINARY @c5_c1_table_sha=BINARY '3ce13c50d1c43e01fc2663fc0e934c578d243aa1be669f679b9b3544d026a586',0),0,1)
  + IF(IFNULL(BINARY @c5_c1_column_sha=BINARY 'edafee513883f44989fe95210292af52b2a9933e4144b96cb344905fe41687d1',0),0,1)
  + IF(IFNULL(BINARY @c5_c1_index_sha=BINARY 'f7b465f9c5e4472166a18c3b06677ddd09b50af89fb7198ba75f2ada75c9d769',0),0,1)
  + IF(@c5_event_table_count=4,0,1)
  + IF(@c5_event_column_count=75,0,1)
  + IF(@c5_event_index_count=23,0,1)
  + IF(IFNULL(BINARY @c5_event_table_sha=BINARY 'ebc4145ba71f9715422c5302bd9d5dca43a7fbe73a40fc8c7dc0f5001a5ef173',0),0,1)
  + IF(IFNULL(BINARY @c5_event_column_sha=BINARY '5182878b36ceaa72f14fd3d7ab268ba2e149d418c97877de309d2d41ed78b2fa',0),0,1)
  + IF(IFNULL(BINARY @c5_event_index_sha=BINARY 'b98e22b93c356602837c9ec6bcf48b9afad9b2bb055418e150fe390f62c399ff',0),0,1);
SET @c5_failures := @c5_failures + @c5_dependency_bad;

SELECT COUNT(*) INTO @c5_legacy_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_cashier_v3_member_event_outbox';
SET @c5_legacy_rows := 0;
SET @c5_legacy_query := IF(
  @c5_legacy_exists=1,
  'SELECT COUNT(*) INTO @c5_legacy_rows FROM `eb_cashier_v3_member_event_outbox`',
  'SET @c5_legacy_rows:=0'
);
PREPARE c5_legacy_stmt FROM @c5_legacy_query;
EXECUTE c5_legacy_stmt;
DEALLOCATE PREPARE c5_legacy_stmt;
SET @c5_failures := @c5_failures + IF(@c5_legacy_rows=0,0,1);

-- Existing member authority must expose the exact bar_code storage contract used by code.
SELECT COUNT(*) INTO @c5_user_table_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_user' AND ENGINE='InnoDB';

SELECT COUNT(*) INTO @c5_bar_column_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_user' AND COLUMN_NAME='bar_code'
  AND COLUMN_TYPE='varchar(32)' AND IS_NULLABLE='NO' AND COLUMN_DEFAULT='';

SET @c5_failures := @c5_failures
  + IF(@c5_user_table_exists=1,0,1)
  + IF(@c5_bar_column_ok=1,0,1);

SELECT COUNT(*) INTO @c5_bar_named_rows
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_user' AND INDEX_NAME='idx_bar_code';

SELECT COUNT(*) INTO @c5_bar_named_exact
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME='eb_user' AND INDEX_NAME='idx_bar_code'
  AND NON_UNIQUE=1 AND SEQ_IN_INDEX=1 AND COLUMN_NAME='bar_code'
  AND SUB_PART IS NULL AND INDEX_TYPE='BTREE';

SET @c5_bar_index_ok := IF(@c5_bar_named_rows=0,1,IF(@c5_bar_named_rows=1 AND @c5_bar_named_exact=1,1,0));
SET @c5_failures := @c5_failures + IF(@c5_bar_index_ok=1,0,1);

-- Exact target-table contract.
DROP TEMPORARY TABLE IF EXISTS _c5_expected_column;
CREATE TEMPORARY TABLE _c5_expected_column (
  table_name varchar(64) NOT NULL,
  column_name varchar(64) NOT NULL,
  ordinal_position int(11) NOT NULL,
  column_type varchar(128) NOT NULL,
  is_nullable varchar(3) NOT NULL,
  default_is_null tinyint(1) NOT NULL,
  column_default varchar(255) NOT NULL DEFAULT '',
  character_set_name varchar(32) NOT NULL DEFAULT '',
  collation_name varchar(64) NOT NULL DEFAULT '',
  extra_value varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (table_name,column_name)
) ENGINE=Memory;

INSERT INTO _c5_expected_column VALUES
('eb_cashier_v3_member_phone_lock','phone',1,'varchar(15)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_member_phone_lock','created_at',2,'int(11) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_member_phone_lock','updated_at',3,'int(11) unsigned','NO',0,'0','','',''),

('eb_cashier_v3_member_number_sequence','sequence_key',1,'varchar(32)','NO',0,'','ascii','ascii_bin',''),
('eb_cashier_v3_member_number_sequence','current_value',2,'int(10) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_member_number_sequence','created_at',3,'int(11) unsigned','NO',0,'0','','',''),
('eb_cashier_v3_member_number_sequence','updated_at',4,'int(11) unsigned','NO',0,'0','','',''),

('eb_member_exclusive_service','id',1,'bigint(20) unsigned','NO',1,'','','','auto_increment'),
('eb_member_exclusive_service','member_id',2,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service','staff_id',3,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service','employee_id',4,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service','store_id',5,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service','staff_name',6,'varchar(64)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service','store_name',7,'varchar(100)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service','source_type',8,'varchar(32)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service','source_business_type',9,'varchar(32)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service','source_business_id',10,'varchar(64)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service','reason',11,'varchar(255)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service','status',12,'tinyint(3) unsigned','NO',0,'1','','',''),
('eb_member_exclusive_service','version',13,'bigint(20) unsigned','NO',0,'1','','',''),
('eb_member_exclusive_service','bound_at',14,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service','operator_id',15,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service','operator_name',16,'varchar(64)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service','idempotency_key',17,'varchar(128)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service','created_at',18,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service','updated_at',19,'int(11) unsigned','NO',0,'0','','',''),

('eb_member_exclusive_service_change','id',1,'bigint(20) unsigned','NO',1,'','','','auto_increment'),
('eb_member_exclusive_service_change','change_key',2,'varchar(128)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service_change','member_id',3,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','previous_staff_id',4,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','previous_employee_id',5,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','previous_store_id',6,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','previous_staff_name',7,'varchar(64)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service_change','previous_store_name',8,'varchar(100)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service_change','current_staff_id',9,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','current_employee_id',10,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','current_store_id',11,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','current_staff_name',12,'varchar(64)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service_change','current_store_name',13,'varchar(100)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service_change','source_type',14,'varchar(32)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service_change','source_business_type',15,'varchar(32)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service_change','source_business_id',16,'varchar(64)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service_change','reason',17,'varchar(255)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service_change','operator_id',18,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','operator_name',19,'varchar(64)','NO',0,'','utf8mb4','utf8mb4_general_ci',''),
('eb_member_exclusive_service_change','idempotency_key',20,'varchar(128)','NO',0,'','ascii','ascii_bin',''),
('eb_member_exclusive_service_change','occurred_at',21,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','recorded_at',22,'int(11) unsigned','NO',0,'0','','',''),
('eb_member_exclusive_service_change','created_at',23,'int(11) unsigned','NO',0,'0','','','');

DROP TEMPORARY TABLE IF EXISTS _c5_expected_index;
CREATE TEMPORARY TABLE _c5_expected_index (
  table_name varchar(64) NOT NULL,
  index_name varchar(64) NOT NULL,
  non_unique tinyint(1) NOT NULL,
  index_type varchar(16) NOT NULL,
  index_columns varchar(255) NOT NULL,
  sub_parts varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (table_name,index_name)
) ENGINE=Memory;

INSERT INTO _c5_expected_index VALUES
('eb_cashier_v3_member_phone_lock','PRIMARY',0,'BTREE','phone',''),
('eb_cashier_v3_member_number_sequence','PRIMARY',0,'BTREE','sequence_key',''),
('eb_member_exclusive_service','PRIMARY',0,'BTREE','id',''),
('eb_member_exclusive_service','uk_member_id',0,'BTREE','member_id',''),
('eb_member_exclusive_service','idx_staff_status',1,'BTREE','staff_id,status',''),
('eb_member_exclusive_service','idx_employee_status',1,'BTREE','employee_id,status',''),
('eb_member_exclusive_service','idx_store_status',1,'BTREE','store_id,status',''),
('eb_member_exclusive_service','idx_bound_at',1,'BTREE','bound_at',''),
('eb_member_exclusive_service_change','PRIMARY',0,'BTREE','id',''),
('eb_member_exclusive_service_change','uk_change_key',0,'BTREE','change_key',''),
('eb_member_exclusive_service_change','idx_member_time',1,'BTREE','member_id,occurred_at,id',''),
('eb_member_exclusive_service_change','idx_previous_staff_time',1,'BTREE','previous_staff_id,occurred_at',''),
('eb_member_exclusive_service_change','idx_current_staff_time',1,'BTREE','current_staff_id,occurred_at',''),
('eb_member_exclusive_service_change','idx_source_business',1,'BTREE','source_business_type,source_business_id','');

SELECT COUNT(*) INTO @c5_target_table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_member_phone_lock',
  'eb_cashier_v3_member_number_sequence',
  'eb_member_exclusive_service',
  'eb_member_exclusive_service_change'
);

SET @c5_partial_tables := IF(@c5_target_table_count IN (0,4),0,1);
SET @c5_failures := @c5_failures + @c5_partial_tables;

DROP TEMPORARY TABLE IF EXISTS _c5_actual_column;
CREATE TEMPORARY TABLE _c5_actual_column AS
SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name,
       ORDINAL_POSITION AS ordinal_position, COLUMN_TYPE AS column_type,
       IS_NULLABLE AS is_nullable, IF(COLUMN_DEFAULT IS NULL,1,0) AS default_is_null,
       IFNULL(COLUMN_DEFAULT,'') AS column_default,
       IFNULL(CHARACTER_SET_NAME,'') AS character_set_name,
       IFNULL(COLLATION_NAME,'') AS collation_name,
       IFNULL(EXTRA,'') AS extra_value
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_member_phone_lock','eb_cashier_v3_member_number_sequence',
  'eb_member_exclusive_service',
  'eb_member_exclusive_service_change'
);

DROP TEMPORARY TABLE IF EXISTS _c5_actual_index;
CREATE TEMPORARY TABLE _c5_actual_index AS
SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name,
       MAX(NON_UNIQUE) AS non_unique, MAX(INDEX_TYPE) AS index_type,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns,
       IF(SUM(SUB_PART IS NOT NULL)=0,'',GROUP_CONCAT(IFNULL(SUB_PART,'') ORDER BY SEQ_IN_INDEX)) AS sub_parts
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_member_phone_lock','eb_cashier_v3_member_number_sequence',
  'eb_member_exclusive_service',
  'eb_member_exclusive_service_change'
)
GROUP BY TABLE_NAME,INDEX_NAME;

SELECT COUNT(*) INTO @c5_table_meta_bad
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=@c5_db AND TABLE_NAME IN (
  'eb_cashier_v3_member_phone_lock','eb_cashier_v3_member_number_sequence',
  'eb_member_exclusive_service',
  'eb_member_exclusive_service_change'
) AND (ENGINE<>'InnoDB' OR TABLE_COLLATION<>'utf8mb4_general_ci');

SELECT COUNT(*) INTO @c5_column_missing
FROM _c5_expected_column e
LEFT JOIN _c5_actual_column a
  ON BINARY a.table_name=BINARY e.table_name AND BINARY a.column_name=BINARY e.column_name
WHERE a.column_name IS NULL;

SELECT COUNT(*) INTO @c5_column_extra
FROM _c5_actual_column a
LEFT JOIN _c5_expected_column e
  ON BINARY e.table_name=BINARY a.table_name AND BINARY e.column_name=BINARY a.column_name
WHERE e.column_name IS NULL;

SELECT COUNT(*) INTO @c5_column_bad
FROM _c5_expected_column e
JOIN _c5_actual_column a
  ON BINARY a.table_name=BINARY e.table_name AND BINARY a.column_name=BINARY e.column_name
WHERE a.ordinal_position<>e.ordinal_position
   OR BINARY a.column_type<>BINARY e.column_type
   OR BINARY a.is_nullable<>BINARY e.is_nullable
   OR a.default_is_null<>e.default_is_null
   OR BINARY a.column_default<>BINARY e.column_default
   OR BINARY a.character_set_name<>BINARY e.character_set_name
   OR BINARY a.collation_name<>BINARY e.collation_name
   OR BINARY a.extra_value<>BINARY e.extra_value;

SELECT COUNT(*) INTO @c5_index_missing
FROM _c5_expected_index e
LEFT JOIN _c5_actual_index a
  ON BINARY a.table_name=BINARY e.table_name AND BINARY a.index_name=BINARY e.index_name
WHERE a.index_name IS NULL;

SELECT COUNT(*) INTO @c5_index_extra
FROM _c5_actual_index a
LEFT JOIN _c5_expected_index e
  ON BINARY e.table_name=BINARY a.table_name AND BINARY e.index_name=BINARY a.index_name
WHERE e.index_name IS NULL;

SELECT COUNT(*) INTO @c5_index_bad
FROM _c5_expected_index e
JOIN _c5_actual_index a
  ON BINARY a.table_name=BINARY e.table_name AND BINARY a.index_name=BINARY e.index_name
WHERE a.non_unique<>e.non_unique
   OR BINARY a.index_type<>BINARY e.index_type
   OR BINARY a.index_columns<>BINARY e.index_columns
   OR BINARY a.sub_parts<>BINARY e.sub_parts;

SET @c5_target_structure_bad := IF(
  @c5_target_table_count=4,
  @c5_table_meta_bad+@c5_column_missing+@c5_column_extra+@c5_column_bad+@c5_index_missing+@c5_index_extra+@c5_index_bad,
  0
);
SET @c5_failures := @c5_failures + @c5_target_structure_bad;

SELECT
  @c5_upgrade_log_exists AS upgrade_log_exists,
  @c5_upgrade_key_used AS upgrade_key_used,
  @c5_c1_key_used AS c1_dependency_key_used,
  @c5_event_key_used AS event_dependency_key_used,
  @c5_dependency_bad AS dependency_failure_count,
  @c5_legacy_exists AS legacy_table_exists,
  @c5_legacy_rows AS legacy_rows,
  @c5_user_table_exists AS user_table_exists,
  @c5_bar_column_ok AS bar_code_column_ok,
  @c5_bar_index_ok AS bar_code_index_ok,
  @c5_target_table_count AS target_table_count,
  @c5_partial_tables AS partial_table_failure,
  @c5_table_meta_bad AS table_meta_bad,
  @c5_column_missing AS column_missing,
  @c5_column_extra AS column_extra,
  @c5_column_bad AS column_bad,
  @c5_index_missing AS index_missing,
  @c5_index_extra AS index_extra,
  @c5_index_bad AS index_bad,
  @c5_failures AS precheck_failure_count;

SET @c5_finish_sql := IF(
  @c5_failures=0,
  'SELECT ''PRECHECK_OK'' AS precheck_result',
  'SELECT * FROM STOP_C5_MEMBER_PRECHECK_FAILED'
);
PREPARE c5_finish_stmt FROM @c5_finish_sql;
EXECUTE c5_finish_stmt;
DEALLOCATE PREPARE c5_finish_stmt;
